<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Year;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Centraliza TODO el calculo de precios. Ni el frontend ni los Controllers
 * calculan un line_total directamente: siempre pasan por aca.
 *
 * FASE 4.1 (simplificacion del flujo de pedidos): la promocion dejo de ser un
 * "tipo" que el usuario elige a mano. Ahora es automatica: si la cantidad de
 * porciones de locro alcanza year->amount_for_promo, TODA la cantidad se
 * cobra a year->promo_unit_price por porcion (no es un precio de combo total,
 * es un precio unitario promocional que reemplaza al precio normal para el
 * pedido completo). Por debajo del umbral se cobra year->portion_price.
 *
 * Ejemplo con portion_price=18000, promo_unit_price=15000, amount_for_promo=3:
 *   1 porcion -> 18000*1 = 18000
 *   2 porciones -> 18000*2 = 36000
 *   3 porciones -> 15000*3 = 45000 (alcanza el umbral)
 *   4 porciones -> 15000*4 = 60000
 *
 * ANTES esta clase interpretaba promo_unit_price como el precio de un
 * "bloque" (cobraba bloques completos a promo + el resto suelto a precio
 * normal), lo cual el usuario confirmo que era la interpretacion incorrecta
 * para esta aplicacion. Se reemplazo enteramente esa logica, no se dejaron
 * las dos conviviendo.
 *
 * Salsas: la cantidad se calcula automaticamente a partir de las porciones
 * de locro (ver calculateSauces) y NUNCA tienen cargo propio (unit_price=0),
 * tal como confirmo el usuario.
 *
 * Integridad historica: esta clase NUNCA se invoca automaticamente al
 * guardar los parametros de un Year (ver YearController::update). Los
 * importes de pedidos ya persistidos no se tocan solos; solo se recalculan
 * cuando se edita explicitamente ESE pedido puntual (cambio de porciones o
 * cambio de anio del pedido).
 */
class PricingService
{
    public const PRICED_PRODUCT = 'locro';
    public const SAUCES_PRODUCT = 'salsas';

    /**
     * Cantidad de salsas incluidas segun la cantidad de porciones de locro,
     * usando la relacion configurable de CADA edicion (Fase 5B):
     *   $year->sauce_portions_per_block  = "Y" en "X salsas cada Y porciones"
     *   $year->sauce_units_per_block     = "X" en "X salsas cada Y porciones"
     *
     * FORMULA: para portions > 0,
     *   sauces = max(unitsPerBlock, intdiv(portions, portionsPerBlock) * unitsPerBlock)
     * Para portions <= 0: 0 salsas.
     *
     * Esto reproduce EXACTAMENTE la regla anterior hardcodeada
     * (max(1, intdiv(portions, 2))) cuando portionsPerBlock=2 y
     * unitsPerBlock=1 (valores default de la migracion, ver
     * add_sauce_config_to_years_table), asi que ninguna edicion existente
     * cambia su comportamiento sin que alguien edite sus Parametros.
     *
     * AMBIGUEDAD DOCUMENTADA (pedida explicitamente resolver antes de
     * generalizar): que pasa con una cantidad de porciones MENOR a un bloque
     * completo (ej. 1 porcion con "2 salsas cada 3 porciones")? La regla
     * original ya resolvia esto para su propio caso (1 porcion, bloque de 2)
     * dando 1 salsa en vez de 0 -- nunca deja un pedido con porciones sin
     * ninguna salsa solo porque no completo un bloque entero. Se generaliza
     * ese mismo criterio: CUALQUIER cantidad de porciones > 0 recibe COMO
     * MINIMO las salsas de UN bloque completo (unitsPerBlock), aunque no
     * haya llegado a completar ese bloque en porciones. Por eso el minimo es
     * "unitsPerBlock" (no "1") en la formula generalizada: para el caso
     * default (unitsPerBlock=1) ambas cosas coinciden, que es por lo que el
     * comportamiento historico no cambia.
     * Ejemplo con "2 salsas cada 3 porciones": 1 o 2 porciones -> 2 salsas
     * (el minimo de un bloque), 3 porciones -> 2 salsas (1 bloque completo),
     * 6 porciones -> 4 salsas (2 bloques completos).
     */
    public function calculateSauces(int $portions, ?Year $year = null): int
    {
        if ($portions <= 0) {
            return 0;
        }

        $portionsPerBlock = max(1, (int) ($year->sauce_portions_per_block ?? 2));
        $unitsPerBlock = max(0, (int) ($year->sauce_units_per_block ?? 1));

        if ($unitsPerBlock === 0) {
            return 0;
        }

        return max($unitsPerBlock, intdiv($portions, $portionsPerBlock) * $unitsPerBlock);
    }

    /**
     * Valida que una combinacion producto/tipo/precio-personalizado sea coherente
     * ANTES de intentar calcularla, para poder devolver errores 422 claros en vez
     * de dejar que calculateLine() tire una InvalidArgumentException (500 feo).
     *
     * @return array<string,string> mapa campo => mensaje (vacio si no hay errores).
     */
    public function validateItemRules(string $product, string $type, ?float $customUnitPrice, ?Year $year): array
    {
        $errors = [];

        if ($type === 'personalizado' && $customUnitPrice === null) {
            $errors['custom_unit_price'] = 'Una linea personalizada requiere indicar un precio unitario.';
        }

        // 'promocion' como tipo explicito queda solo por compatibilidad hacia atras
        // (datos historicos / uso avanzado directo de la API); el flujo simplificado
        // nunca lo envia, la promocion es automatica dentro de calculateNormal().
        if ($type === 'promocion' && $product !== self::PRICED_PRODUCT) {
            $errors['product'] = 'Las promociones solo aplican al producto locro.';
        }

        return $errors;
    }

    /**
     * Calcula unit_price y line_total para una linea de pedido nueva o editada.
     *
     * @param  string  $product  locro|batata|salsas|pastelitos|otro
     * @param  string  $type  normal|regalo|promocion|personalizado
     * @param  int  $quantity  cantidad fisica de la linea (>= 0; 0 solo valido para salsas)
     * @param  Year  $year  anio vigente (define los precios)
     * @param  float|null  $customUnitPrice  obligatorio si $type === 'personalizado'
     * @return array{unit_price: string, line_total: string}
     */
    public function calculateLine(
        string $product,
        string $type,
        int $quantity,
        Year $year,
        ?float $customUnitPrice = null,
    ): array {
        if ($quantity < 0) {
            throw new InvalidArgumentException('La cantidad de una linea de pedido no puede ser negativa.');
        }

        return match ($type) {
            'regalo' => [
                'unit_price' => '0.00',
                'line_total' => '0.00',
            ],
            'personalizado' => $this->calculatePersonalizado($quantity, $customUnitPrice),
            // 'promocion' explicito usa la MISMA formula automatica que 'normal' para
            // locro (ya no existen dos formulas distintas, ver docblock de la clase).
            'promocion', 'normal' => $this->calculateNormal($product, $quantity, $year),
            default => throw new InvalidArgumentException("Tipo de linea desconocido: {$type}"),
        };
    }

    /**
     * Precio normal/automatico. Para 'locro': aplica el umbral de promocion
     * automaticamente (ver docblock de la clase). Para 'salsas' y cualquier
     * otro producto que no sea el principal: siempre $0 (sin cargo propio).
     */
    protected function calculateNormal(string $product, int $quantity, Year $year): array
    {
        if ($product !== self::PRICED_PRODUCT) {
            return ['unit_price' => '0.00', 'line_total' => '0.00'];
        }

        $promoActive = $year->amount_for_promo && $year->promo_unit_price
            && $quantity >= $year->amount_for_promo;

        $unitPrice = (string) ($promoActive ? $year->promo_unit_price : $year->portion_price);

        return [
            'unit_price' => $unitPrice,
            'line_total' => bcmul((string) $quantity, $unitPrice, 2),
        ];
    }

    protected function calculatePersonalizado(int $quantity, ?float $customUnitPrice): array
    {
        if ($customUnitPrice === null) {
            throw new InvalidArgumentException('Una linea personalizada requiere un precio unitario.');
        }

        $unitPrice = number_format($customUnitPrice, 2, '.', '');

        return [
            'unit_price' => $unitPrice,
            'line_total' => bcmul((string) $quantity, $unitPrice, 2),
        ];
    }

    /**
     * Indica si, para una cantidad dada de porciones de locro, se esta
     * aplicando el precio promocional en el anio indicado. Puramente
     * informativo para la UI (badge "Promo"); el calculo real de precio
     * sigue siendo calculateNormal/calculateLine.
     */
    public function isPromoActive(int $portions, Year $year): bool
    {
        return (bool) ($year->amount_for_promo && $year->promo_unit_price && $portions >= $year->amount_for_promo);
    }

    /**
     * Recalcula TODOS los pedidos NO CANCELADOS de un anio, usando los
     * parametros ACTUALES del Year. A diferencia de antes, esto YA NO es un
     * metodo "de emergencia" sin invocar: ahora es el corazon de la opcion
     * explicita "Guardar y recalcular pedidos" en la pantalla de Parametros
     * (ver YearController::update). Sigue sin dispararse jamas por si solo:
     * alguien tiene que pedirlo explicitamente cada vez.
     *
     * Reutiliza recalculatePricedLinesForOrder() por cada pedido (no duplica
     * la logica de calculo de precio ni la de recalculo de totales).
     *
     * PEDIDOS CANCELADOS: se excluyen deliberadamente. Un pedido 'cancelado'
     * ya no representa una venta real esperada; recalcularle el importe
     * podria generar un saldo pendiente "fantasma" sobre algo que nadie va a
     * cobrar ni entregar, y no aporta valor operativo. Se prioriza el estado
     * "cancelado" como congelado/historico tal como quedo, sobre mantenerlo
     * sincronizado con precios que ya no aplican a una venta cancelada.
     *
     * PAGOS: nunca se tocan. recalculatePricedLinesForOrder() solo reescribe
     * unit_price/line_total de las lineas 'normal'/'promocion' de producto
     * 'locro' (nunca 'regalo'/'personalizado', nunca la tabla payments), y
     * Order::recalculateTotals() SIEMPRE deriva total_paid sumando los
     * payments reales existentes (nunca los recrea ni modifica), por lo que
     * balance_due queda correctamente actualizado (total_amount - total_paid)
     * sin que un solo payment cambie.
     *
     * TRANSACCIONAL: si algo falla a mitad de camino, se revierte todo (no
     * queda una parte de los pedidos del anio actualizada y otra no).
     *
     * @return int cantidad de PEDIDOS actualizados (no de lineas).
     */
    public function recalculateAllOrdersForYear(Year $year): int
    {
        $updatedOrders = 0;

        DB::transaction(function () use ($year, &$updatedOrders) {
            Order::query()
                ->where('year_id', $year->id)
                ->where('status', '!=', 'cancelado')
                ->chunkById(100, function ($orders) use ($year, &$updatedOrders) {
                    foreach ($orders as $order) {
                        $this->recalculatePricedLinesForOrder($order, $year);
                        $updatedOrders++;
                    }
                });
        });

        return $updatedOrders;
    }

    /**
     * Recalcula las lineas 'normal'/'promocion' de producto 'locro' de UN
     * pedido puntual contra los parametros de $newYear, y recalcula sus
     * totales. Dos llamadores legitimos:
     *   1. OrderController::update, cuando se edita explicitamente el ANIO
     *      de ESE pedido puntual (accion deliberada sobre un pedido).
     *   2. PricingService::recalculateAllOrdersForYear, cuando el usuario
     *      elige explicitamente "Guardar y recalcular pedidos" en Parametros
     *      (en ese caso $newYear es el MISMO year del pedido, con params nuevos).
     * 'regalo' y 'personalizado' NUNCA se tocan (excepciones manuales), ni
     * tampoco la cantidad (quantity) de las lineas de locro: solo
     * unit_price/line_total.
     *
     * EXCEPCION: la linea 'salsas' SI tiene su quantity recalculada aca. A
     * diferencia de 'locro' (cuya quantity es una decision manual del
     * usuario -- la cantidad de porciones que pidio), la quantity de
     * 'salsas' es un valor 100% DERIVADO de portions + la configuracion de
     * la edicion (calculateSauces). Si cambia esa configuracion (Parametros)
     * y no se recalcula tambien esta linea, "Guardar y recalcular pedidos"
     * deja la cantidad de salsas de TODOS los pedidos existentes desactualizada
     * para siempre, aunque el precio si se haya recalculado -- ese fue el bug
     * original que motivo este comentario.
     */
    public function recalculatePricedLinesForOrder(Order $order, Year $newYear): void
    {
        $primaryPortions = 0;

        OrderItem::withoutEvents(function () use ($order, $newYear, &$primaryPortions) {
            $order->items()
                ->whereIn('type', ['normal', 'promocion'])
                ->where('product', self::PRICED_PRODUCT)
                ->get()
                ->each(function (OrderItem $item) use ($newYear, &$primaryPortions) {
                    $result = $this->calculateLine($item->product, $item->type, $item->quantity, $newYear);
                    $item->forceFill([
                        'unit_price' => $result['unit_price'],
                        'line_total' => $result['line_total'],
                    ])->save();
                    $primaryPortions += $item->quantity;
                });

            $saucesItem = $order->items()->where('product', self::SAUCES_PRODUCT)->where('type', 'normal')->first();
            if ($saucesItem) {
                $saucesItem->update(['quantity' => $this->calculateSauces($primaryPortions, $newYear)]);
            }
        });

        $order->recalculateTotals();
    }

    /**
     * Crea una linea de pedido persistida, calculando su precio con este servicio.
     */
    public function addItemToOrder(
        Order $order,
        string $product,
        string $type,
        int $quantity,
        Year $year,
        ?string $description = null,
        ?float $customUnitPrice = null,
        ?int $createdBy = null,
    ): OrderItem {
        $pricing = $this->calculateLine($product, $type, $quantity, $year, $customUnitPrice);

        return $order->items()->create([
            'product' => $product,
            'type' => $type,
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $pricing['unit_price'],
            'line_total' => $pricing['line_total'],
            'created_by' => $createdBy,
        ]);
    }

    /**
     * Punto de entrada del flujo SIMPLIFICADO (Fase 4.1): dado un pedido y una
     * cantidad de porciones de locro, crea o actualiza EN UNA SOLA OPERACION
     * las dos lineas estandar del pedido:
     *   - product=locro, type=normal, quantity=$portions, precio automatico.
     *   - product=salsas, type=normal, quantity=calculateSauces($portions), $0.
     * Si $portions es 0, ambas lineas se eliminan (pedido sin porciones de
     * locro no tiene sentido en el flujo simple; si se necesitan solo lineas
     * de excepcion, eso se maneja aparte via OrderItemController).
     * Nunca toca lineas 'regalo'/'personalizado'/'promocion' explicitas que
     * el usuario haya agregado por "Opciones avanzadas": esas son independientes.
     */
    public function syncPortionsForOrder(Order $order, int $portions, Year $year, ?int $userId = null): void
    {
        if ($portions < 0) {
            throw new InvalidArgumentException('La cantidad de porciones no puede ser negativa.');
        }

        OrderItem::withoutEvents(function () use ($order, $portions, $year, $userId) {
            $locroItem = $order->items()->where('product', self::PRICED_PRODUCT)->where('type', 'normal')->first();
            $saucesItem = $order->items()->where('product', self::SAUCES_PRODUCT)->where('type', 'normal')->first();

            if ($portions === 0) {
                $locroItem?->delete();
                $saucesItem?->delete();
            } else {
                $locroPricing = $this->calculateLine(self::PRICED_PRODUCT, 'normal', $portions, $year);
                if ($locroItem) {
                    $locroItem->update([
                        'quantity' => $portions,
                        'unit_price' => $locroPricing['unit_price'],
                        'line_total' => $locroPricing['line_total'],
                    ]);
                } else {
                    $order->items()->create([
                        'product' => self::PRICED_PRODUCT,
                        'type' => 'normal',
                        'quantity' => $portions,
                        'unit_price' => $locroPricing['unit_price'],
                        'line_total' => $locroPricing['line_total'],
                        'created_by' => $userId,
                    ]);
                }

                $sauces = $this->calculateSauces($portions, $year);
                if ($saucesItem) {
                    $saucesItem->update(['quantity' => $sauces, 'unit_price' => '0.00', 'line_total' => '0.00']);
                } else {
                    $order->items()->create([
                        'product' => self::SAUCES_PRODUCT,
                        'type' => 'normal',
                        'quantity' => $sauces,
                        'unit_price' => '0.00',
                        'line_total' => '0.00',
                        'created_by' => $userId,
                    ]);
                }
            }
        });

        $order->recalculateTotals();
    }
}
