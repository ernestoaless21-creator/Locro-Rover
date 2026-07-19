<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Http\Requests\UpdatePortionsRequest;
use App\Models\Client;
use App\Models\ClientAssignment;
use App\Models\Gift;
use App\Models\Loss;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\Year;
use App\Services\ClientAssignmentService;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    /**
     * Tabla principal de pedidos del anio activo (o el ?year_id= indicado).
     * Filtro directo por Rover via ?rover_id= (soporta un solo id; multiple
     * seleccion se resuelve en el frontend mandando varios requests o, si
     * se prefiere, se puede extender a ?rover_ids[]= facilmente).
     *
     * Un Rover sin 'pedidos.ver-todos' SOLO ve sus propios pedidos, sin
     * importar que parametro de filtro mande en la URL (se fuerza server-side,
     * ignorar esto seria justamente el hueco de seguridad que pidieron evitar).
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Order::class);

        $year = $request->filled('year_id')
            ? Year::findOrFail($request->get('year_id'))
            : Year::where('is_active', true)->firstOrFail();

        $user = $request->user();
        $canViewAll = $user->can('pedidos.ver-todos');
        // Fase 7 (correccion 4), seccion 3: mismo gate que ya usa
        // DashboardController para la recaudacion global.
        $canViewFinancials = Gate::allows('viewGlobalFinancials', Order::class);
        // Fase 9 (correccion): mismo gate que usa el Dashboard para
        // gifted_portions/lost_portions (ver compactCounters mas abajo).
        $canViewProduction = $user->can('produccion.ver');

        // Fase P2 (correccion de orden): antes se ordenaba por created_at
        // DESC (orden cronologico de carga), que con el import historico
        // masivo generaba bloques que PARECIAN alfabeticos por casualidad
        // (el Excel de origen ya venia ordenado por apellido y las filas se
        // insertan una a una en ese mismo orden durante el import, asi que
        // sus created_at terminaban en esa misma secuencia), pero se rompian
        // en cuanto se mezclaban con pedidos cargados en vivo (con
        // created_at real, sin relacion con el apellido) o con otra tanda de
        // import corrida en otro momento (otro bloque "alfabetico" propio).
        // Ahora se ordena explicitamente por apellido/nombre del cliente,
        // resuelto ACA en SQL (no en Vue) via un LEFT JOIN a `clients` +
        // select('orders.*') (evita que las columnas de `clients` pisen las
        // de `orders` con el mismo nombre, ej. id/created_at, al hidratar el
        // modelo). Los filtros/busqueda/ausencia de paginacion de mas abajo
        // siguen aplicando sobre esta misma query, sin cambios.
        $lastNameSort = $this->clientNameSortExpression('clients.last_name');
        $firstNameSort = $this->clientNameSortExpression('clients.first_name');
        // Grupo de orden: 0) placeholder de import con apellido faltante
        // (ver RowTransformer::MISSING_NAME_PLACEHOLDER, se guarda como
        // "Completar" tras Client::normalizeName) SIEMPRE primero; 1)
        // apellidos que, ya normalizados, no arrancan con una letra a-z
        // (simbolos sueltos, numeros, vacio); 2) el resto, alfabetico normal.
        $nameSortGroup = 'case '.
            "when {$lastNameSort} = 'completar' then 0 ".
            "when {$lastNameSort} = '' or substr({$lastNameSort}, 1, 1) not between 'a' and 'z' then 1 ".
            'else 2 end';

        $orders = Order::query()
            ->select('orders.*')
            ->leftJoin('clients', 'clients.id', '=', 'orders.client_id')
            ->with(['client:id,first_name,last_name,phone', 'rover:id,name', 'withdrawnBy:id,name', 'items', 'payments'])
            ->where('orders.year_id', $year->id)
            ->when(! $canViewAll, fn ($q) => $q->where('rover_id', $user->id))
            ->when($canViewAll && $request->filled('rover_id'), fn ($q) => $q->where('rover_id', $request->get('rover_id')))
            ->when($request->filled('withdrawal_status'), fn ($q) => $q->where('withdrawal_status', $request->get('withdrawal_status')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->get('status')))
            ->when($request->filled('payment_status'), function ($q) use ($request) {
                match ($request->get('payment_status')) {
                    'pagado' => $q->whereColumn('total_paid', '>=', 'total_amount')->where('total_amount', '>', 0),
                    'pendiente' => $q->where('total_paid', 0),
                    'parcial' => $q->where('total_paid', '>', 0)->whereColumn('total_paid', '<', 'total_amount'),
                    default => null,
                };
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = trim($request->get('search'));
                $q->whereHas('client', fn ($cq) => $cq->searchTerm($term));
            })
            // Fase P2 (UX): filtro EXACTO por cliente, usado cuando se elige
            // una sugerencia del autocomplete (ver Orders/Index.vue,
            // pickSuggestion). A proposito NO reutiliza 'search': ese es un
            // LIKE tolerante (Client::scopeSearchTerm) que tambien matchea
            // contra telefono, asi que un N° historico corto podia traer de
            // rebote clientes cuyo telefono contuviera esa secuencia. client_id
            // es una igualdad exacta, sin falsos positivos posibles.
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->get('client_id')))
            // Fase 7 (correccion 4), seccion 4: filtro Delivery/Retiro para
            // organizar los recorridos del dia del Locro. IMPORTANTE: se
            // reutiliza take_away tal cual ya existe (ver migracion
            // 2026_01_01_000009 y 2026_07_13_000004): true = "retira en
            // mano", false = delivery. No se invierte esa semantica.
            ->when($request->filled('delivery_type') && in_array($request->get('delivery_type'), ['delivery', 'retiro'], true), function ($q) use ($request) {
                $q->where('take_away', $request->get('delivery_type') === 'retiro');
            })
            // Fase 8: "Mis clientes asignados" — pedidos cuyo cliente tiene
            // assigned_user_id = auth user en client_year_assignments para
            // esta edicion. Distinto de rover_id: un cliente puede estar
            // asignado al usuario incluso si la venta la cargo otro Rover.
            ->when($request->boolean('my_assigned_clients'), function ($q) use ($user, $year) {
                $q->whereIn('client_id', ClientAssignment::query()
                    ->where('year_id', $year->id)
                    ->where('assigned_user_id', $user->id)
                    ->select('client_id')
                );
            })
            // Fase 8 (correccion): "Mis ventas" — pedidos con rover_id = usuario
            // autenticado. Misma semantica que roverRanking y my_portions:
            // la venta se atribuye al responsable actual (rover_id), no a quien
            // registro el pedido (created_by). Reemplaza el anterior filtro
            // "Mis pedidos cargados" (created_by).
            ->when($request->boolean('my_sales'), fn ($q) => $q->where('rover_id', $user->id))
            // Fase 8 (correccion): indicadores de retiro clickeables. "Por retirar"
            // cubre pedidos no retirados o con retiro parcial; "Retiradas" son
            // los completamente retirados. La UI los hace mutuamente excluyentes;
            // el backend acepta cada uno de forma independiente.
            ->when($request->boolean('pending_withdrawal'), fn ($q) => $q->whereIn('withdrawal_status', ['no_retirado', 'parcial']))
            ->when($request->boolean('withdrawn'), fn ($q) => $q->where('withdrawal_status', 'retirado'))
            // Fase 8 (correccion): filtros por medio de pago. Cuando ambos estan
            // activos simultaneamente, se aplican como AND (pedidos con pagos en
            // AMBOS medios — ej. anticipo efectivo + saldo transferencia).
            ->when($request->boolean('pay_efectivo'), function ($q) {
                $q->whereHas('payments', fn ($pq) => $pq->whereHas('method', fn ($mq) => $mq->where('slug', 'efectivo')));
            })
            ->when($request->boolean('pay_transferencia'), function ($q) {
                $q->whereHas('payments', fn ($pq) => $pq->whereHas('method', fn ($mq) => $mq->where('slug', 'transferencia')));
            })
            ->orderByRaw("{$nameSortGroup} asc")
            ->orderByRaw("{$lastNameSort} asc")
            ->orderByRaw("{$firstNameSort} asc")
            // Desempate estable: mismo apellido+nombre no debe "saltar" de
            // lugar entre cargas de pagina.
            ->orderBy('orders.id')
            // Fase P2 (UX): se elimina la paginacion a pedido explicito del
            // usuario (volver al comportamiento de "una sola lista larga" de
            // la pagina anterior). $orders pasa a ser una Collection comun en
            // vez de un LengthAwarePaginator; los filtros y el buscador
            // siguen aplicando ANTES de esto (misma query), asi que solo
            // devuelven las filas que ya matchean -- no se trae toda la tabla
            // sin filtrar. Las relaciones ya venian eager-loaded arriba
            // (client/rover/withdrawnBy/items/payments), sin cambios.
            ->get();

        // Fase 7 (correccion 2), seccion 3: Client.general_notes, ClientAssignment.notes
        // (seguimiento cliente+edicion) y Order.observations (logistica de ESE pedido
        // puntual) son 3 campos semanticamente distintos, y no se fusionan (ver
        // decision completa en el docblock de Clients/Index.vue y en el informe de
        // esta correccion). Para que Pedidos deje de "esconder" el dato de
        // seguimiento del cliente, se adjunta aca (solo lectura) el `notes` de la
        // asignacion cliente/edicion de cada pedido, con UN solo query adicional
        // (evita N+1), sin tocar Order::observations.
        $clientIds = $orders->pluck('client_id')->unique();
        $assignmentNotes = ClientAssignment::query()
            ->where('year_id', $year->id)
            ->whereIn('client_id', $clientIds)
            ->pluck('notes', 'client_id');

        $orders->transform(function (Order $order) use ($assignmentNotes) {
            $order->setAttribute('client_assignment_notes', $assignmentNotes->get($order->client_id));

            return $order;
        });

        return Inertia::render('Orders/Index', [
            'orders' => $orders,
            'year' => $year,
            'years' => Year::orderByDesc('year')->get(['id', 'year', 'label', 'is_active']),
            // Solo se manda el listado de rovers si el usuario puede filtrar por rover;
            // asi el frontend no recibe (ni podria mostrar) esa lista sin permiso.
            'rovers' => $canViewAll
                ? User::permission('pedidos.editar')->active()->orderBy('name')->get(['id', 'name'])
                : [],
            'filters' => $request->only('rover_id', 'withdrawal_status', 'status', 'payment_status', 'search', 'client_id', 'delivery_type', 'my_assigned_clients', 'my_sales', 'pending_withdrawal', 'withdrawn', 'pay_efectivo', 'pay_transferencia'),
            'paymentMethods' => PaymentMethod::where('is_active', true)->get(['id', 'name']),
            'canAssignRover' => $user->can('pedidos.asignar-rover'),
            'canRegisterPayment' => $user->can('pagos.registrar'),
            'canWithdraw' => $user->can('pedidos.retirar'),
            // Fase 20 (bug de permisos): gatea la UI de seleccion masiva
            // (checkboxes de fila + barra de acciones) de forma exclusiva e
            // independiente de canRegisterPayment/canWithdraw, que siguen
            // habilitando la accion INDIVIDUAL (checkbox "Retirado" de cada
            // fila) para "todos venden". Ver OrderBulkController y
            // RequiresBulkOrdersPermission.
            'canBulkActions' => $user->can('pedidos.acciones-masivas'),
            // Fase 7, seccion 11: contadores compactos, calculados SIEMPRE sobre
            // TODOS los pedidos validos (no cancelados) de la edicion activa,
            // sin importar los filtros de la tabla ni el estado de pago (ver
            // seccion 11 del prompt: "esten pagos o no"). Misma base/criterio
            // que usa el Dashboard, para no tener dos formulas distintas.
            'counters' => $this->compactCounters($year, $user, $canViewAll, $canViewFinancials, $canViewProduction),
            // Fase 7 (correccion 4), seccion 2: los badges de Regalos/Perdidas
            // solo son CLICKEABLES si el usuario tiene el permiso real de
            // gestionarlos (mismo permiso que ya gatea esos botones en el
            // Dashboard); si no, se muestran igual pero sin link, para no
            // llevar a un usuario sin acceso a un 403.
            'canManageGifts' => $user->can('regalos.gestionar'),
            'canManageLosses' => $user->can('perdidas.gestionar'),
            // Fase 8: ranking de porciones por Rover, SOLO para quienes pueden
            // ver todos los pedidos. Un Rover sin ese permiso no recibe este
            // dato (el array seria parcial y engañoso si se computara solo
            // con sus propios pedidos).
            'roverRanking' => $canViewAll ? $this->roverRanking($year) : null,
        ]);
    }

    /**
     * Fase P2 (correccion de orden): expresion SQL que normaliza una columna
     * de nombre de cliente (apellido o nombre) para poder compararla/
     * ordenarla ignorando mayusculas y acentos, ver docblock de index().
     *
     * SQLite no trae collation Unicode por defecto: su lower() solo pliega
     * ASCII a-z y deja intactas las vocales acentuadas (Á/á, É/é, etc.), asi
     * que hay que reemplazarlas explicitamente por su equivalente sin acento
     * ANTES de que lower() se encargue del resto (mayusculas ASCII simples).
     * ñ/Ñ se pliega a "n" con el mismo criterio (orden practico, no
     * alfabetico RAE estricto) para no crear un grupo aparte de una sola
     * letra. coalesce(...,'') cubre el caso defensivo de un cliente
     * inexistente (LEFT JOIN sin match).
     */
    private function clientNameSortExpression(string $column): string
    {
        $accentMap = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n'];

        $expr = "coalesce({$column}, '')";
        foreach ($accentMap as $accented => $plain) {
            $expr = "replace({$expr}, '{$accented}', '{$plain}')";
            $expr = "replace({$expr}, '".mb_strtoupper($accented, 'UTF-8')."', '{$plain}')";
        }

        return "lower({$expr})";
    }

    /**
     * Fase 7, seccion 11 (correccion 4, secciones 2 y 3). Reutiliza la misma
     * nocion de "pedidos validos" que el Dashboard (no cancelados) y la misma
     * fuente de verdad de atribucion personal (Order::rover_id, ver decision
     * de arquitectura de la seccion 6).
     *
     * "Regalos/perdidas" (Fase 9, correccion de UX): el indicador muestra la
     * SUMA de porciones (`gifted_portions`/`lost_portions`), no la cantidad
     * de registros — un regalo de 2 porciones a Josefina + uno de 2 a
     * Carsten debe verse como "4", no como "2 regalos". Esa suma es
     * exactamente la misma metrica sensible que el Dashboard expone en su
     * bloque de produccion real, gateada con 'produccion.ver' (revela
     * stock/produccion). Por eso, a diferencia del criterio anterior
     * (conteo de registros, que no era sensible y no se gateaba), ahora SI
     * se gatea con ese permiso: si el usuario no lo tiene, ninguna de las
     * dos claves se agrega al array y el frontend no muestra el indicador.
     *
     * La recaudacion reutiliza EXACTAMENTE la misma consulta ya validada de
     * DashboardController::index (Payment agrupado por payment_method_id
     * sobre los pedidos NO cancelados de la edicion), separando por el
     * `slug` real de PaymentMethod ('efectivo'/'transferencia', ver
     * RolesAndPermissionsSeeder) en vez de asumir nombres o ids fijos.
     */
    protected function compactCounters(Year $year, User $user, bool $canViewAll, bool $canViewFinancials, bool $canViewProduction): array
    {
        $activeBase = Order::query()
            ->where('year_id', $year->id)
            ->where('status', '!=', 'cancelado')
            ->when(! $canViewAll, fn ($q) => $q->where('rover_id', $user->id));

        $portionsTotal = (int) (clone $activeBase)->sum('total_portions');
        $personalPortions = (int) (clone $activeBase)->where('rover_id', $user->id)->sum('total_portions');
        $saucesTotal = (int) OrderItem::query()
            ->where('product', 'salsas')
            ->whereIn('order_id', (clone $activeBase)->select('id'))
            ->sum('quantity');

        // Fase 8 (correccion): contadores de retiro para los indicadores
        // clickeables "Por retirar" y "Retiradas". Misma base que el resto:
        // pedidos no cancelados, respetando el scope de canViewAll.
        $portionsPendingWithdrawal = (int) (clone $activeBase)->whereIn('withdrawal_status', ['no_retirado', 'parcial'])->sum('total_portions');
        $portionsWithdrawn = (int) (clone $activeBase)->where('withdrawal_status', 'retirado')->sum('total_portions');

        $counters = [
            'portions_total' => $portionsTotal,
            'sauces_total' => $saucesTotal,
            'my_portions' => $personalPortions,
            'portions_pending_withdrawal' => $portionsPendingWithdrawal,
            'portions_withdrawn' => $portionsWithdrawn,
        ];

        if ($canViewProduction) {
            $counters['gifted_portions'] = (int) Gift::query()->where('year_id', $year->id)->sum('quantity');
            $counters['lost_portions'] = (int) Loss::query()->where('year_id', $year->id)->sum('quantity');
        }

        if ($canViewFinancials) {
            // Misma consulta que DashboardController::index (financials.by_method):
            // suma de Payment.amount agrupada por medio de pago, sobre los
            // pedidos NO cancelados de esta edicion. Incluye pagos parciales/
            // anticipos porque se basa en los PAGOS reales, no en el total
            // teorico del pedido.
            $collectedByMethod = Payment::query()
                ->select('payment_method_id', DB::raw('sum(amount) as total'))
                ->whereIn('order_id', (clone $activeBase)->select('id'))
                ->groupBy('payment_method_id')
                ->get()
                ->keyBy('payment_method_id');

            $methods = PaymentMethod::query()->get(['id', 'name', 'slug']);

            $bySlug = $methods->mapWithKeys(fn ($m) => [
                $m->slug => (float) ($collectedByMethod[$m->id]->total ?? 0),
            ]);

            $counters['collected'] = [
                'total' => (float) $bySlug->sum(),
                'efectivo' => (float) ($bySlug['efectivo'] ?? 0),
                // "Banco" en la UI = todo lo que no sea efectivo (hoy solo
                // 'transferencia' existe como segundo medio, ver seccion 8 del
                // PROJECT_CONTEXT.md: "Los unicos medios de pago normales son
                // Efectivo y Transferencia"). Se suma cualquier medio no-efectivo
                // en vez de asumir que el slug se llama literalmente
                // 'transferencia', por si en el futuro se agrega otro medio
                // bancario sin tocar este calculo.
                'banco' => (float) $bySlug->reject(fn ($amount, $slug) => $slug === 'efectivo')->sum(),
            ];
        }

        return $counters;
    }

    /**
     * Fase 8: ranking de porciones vendidas por Rover para la edicion dada.
     * Solo pedidos NO cancelados y con rover asignado. Ordenado de mayor a
     * menor. No usa created_by (auditor immutable); usa rover_id (responsable
     * actual segun la regla de negocio del proyecto, ver
     * ClientAssignmentService::syncResponsibleForClientYear).
     *
     * @return list<array{rover_id:int,name:string,total_portions:int}>
     */
    protected function roverRanking(Year $year): array
    {
        $rows = Order::query()
            ->select('rover_id', DB::raw('SUM(total_portions) as total_portions'))
            ->where('year_id', $year->id)
            ->where('status', '!=', 'cancelado')
            ->whereNotNull('rover_id')
            ->groupBy('rover_id')
            ->orderByDesc('total_portions')
            ->get();

        $rovers = User::whereIn('id', $rows->pluck('rover_id'))
            ->get(['id', 'name'])
            ->keyBy('id');

        return $rows->map(fn ($row) => [
            'rover_id' => $row->rover_id,
            'name' => $rovers[$row->rover_id]?->name ?? "Rover #{$row->rover_id}",
            'total_portions' => (int) $row->total_portions,
        ])->all();
    }

    /**
     * Pantalla de alta de pedido (Orders/New.vue). Los rovers seleccionables
     * solo se mandan si el usuario puede asignar rover; si no, el pedido queda
     * asignado automaticamente a si mismo (ver store()).
     */
    public function create(Request $request): Response
    {
        Gate::authorize('create', Order::class);

        $user = $request->user();

        return Inertia::render('Orders/New', [
            'years' => Year::orderByDesc('year')->get(['id', 'year', 'label', 'is_active', 'portion_price', 'promo_unit_price', 'amount_for_promo']),
            'rovers' => $user->can('pedidos.asignar-rover')
                ? User::permission('pedidos.editar')->active()->orderBy('name')->get(['id', 'name'])
                : [],
            'canAssignRover' => $user->can('pedidos.asignar-rover'),
            'canExceptionalPrice' => $user->can('pedidos.precio-excepcional'),
            // Fase 18.1: un usuario comun siempre crea en la edicion activa,
            // sin poder elegirla (ver store(), que la fuerza igual del lado
            // servidor). Solo quien administra ediciones puede elegir otra.
            'canChooseYear' => $user->can('anios.gestionar'),
            'paymentMethods' => PaymentMethod::where('is_active', true)->get(['id', 'name']),
            'canRegisterPayment' => $user->can('pagos.registrar'),
            // Fase 6A: permite pre-cargar cliente/edicion cuando se llega desde
            // "Crear pedido" en la pantalla de Asignaciones (?client_id=&year_id=).
            'preselectedClient' => $request->filled('client_id')
                ? Client::find($request->integer('client_id'), ['id', 'first_name', 'last_name', 'phone', 'address'])
                : null,
            'preselectedYearId' => $request->integer('year_id') ?: null,
        ]);
    }

    /**
     * FASE 4.1: alta simplificada. El usuario solo ingresa 'portions'; las
     * lineas de locro+salsas se arman automaticamente via
     * PricingService::syncPortionsForOrder (precio y salsas SIEMPRE
     * calculados en el backend, nunca confiando en lo que mande el navegador).
     * 'advanced_items' (opcional) son las excepciones manuales de "Opciones
     * avanzadas" (regalo/personalizado), se agregan aparte de la linea principal.
     */
    public function store(StoreOrderRequest $request, PricingService $pricing, ClientAssignmentService $assignments): RedirectResponse
    {
        $data = $request->validated();

        // Fase 18.1: un usuario sin 'anios.gestionar' siempre crea en la
        // edicion activa, sin importar que year_id haya mandado el frontend
        // (que ademas ya no deberia mostrarle un selector, ver
        // Orders/New.vue). Se fuerza aca tambien del lado servidor: nunca se
        // confia unicamente en que el frontend oculto el campo.
        $year = $request->user()->can('anios.gestionar')
            ? Year::findOrFail($data['year_id'])
            : Year::where('is_active', true)->firstOrFail();
        $data['year_id'] = $year->id;

        // Fase 19: defensa en profundidad -- la resolucion de arriba ya
        // garantiza que $year sea editable por este usuario (activa, o
        // explicitamente elegida por alguien con 'anios.gestionar'), pero se
        // re-chequea con la misma regla centralizada que el resto de la app
        // para no depender unicamente de esta logica puntual.
        Gate::authorize('mutate', $year);

        // Fase 6A: un pedido nuevo nace "sin responsable" hasta que se le
        // asigna uno. Si no se manda rover_id (o se manda el propio), es una
        // autoasignacion normal (cualquiera con 'pedidos.crear' puede
        // asignarselo a si mismo). Si se manda un rover_id de OTRO usuario,
        // eso es una asignacion directa a un tercero y requiere el permiso
        // de transferencia ('pedidos.asignar-rover'), igual que cambiarle el
        // responsable a un pedido ya existente (ver OrderPolicy::assignRover).
        $targetRoverId = $data['rover_id'] ?? $request->user()->id;
        Gate::authorize('assignRover', [new Order(['rover_id' => null]), $targetRoverId]);

        $order = DB::transaction(function () use ($data, $year, $request, $pricing, $targetRoverId, $assignments) {
            // Fase 5C: por defecto (si el frontend no manda 'take_away'), el
            // pedido se considera retira en mano (true), no delivery. El
            // checkbox visible en New.vue/Edit.vue es "Es delivery" (invertido):
            // desmarcado (caso comun) -> take_away = true.
            $order = Order::create([
                'client_id' => $data['client_id'],
                'year_id' => $data['year_id'],
                'rover_id' => $targetRoverId,
                'take_away' => $data['take_away'] ?? true,
                'delivery_address' => ($data['take_away'] ?? true) ? null : ($data['delivery_address'] ?? null),
                'observations' => $data['observations'] ?? null,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);

            // Crea/actualiza las lineas estandar (locro + salsas automaticas)
            // y recalcula los totales UNA vez (ver docblock del metodo).
            $pricing->syncPortionsForOrder($order, $data['portions'], $year, $request->user()->id);

            // Excepciones manuales de "Opciones avanzadas" (regalo/personalizado),
            // siempre sobre producto 'locro': se suman aparte de la linea principal.
            foreach ($data['advanced_items'] ?? [] as $item) {
                $pricing->addItemToOrder(
                    order: $order,
                    product: 'locro',
                    type: $item['type'],
                    quantity: $item['quantity'],
                    year: $year,
                    description: $item['description'] ?? null,
                    customUnitPrice: $item['custom_unit_price'] ?? null,
                    createdBy: $request->user()->id,
                );
            }

            $order->recalculateTotals();

            // Fase 6A, seccion 7: al crearse un pedido real, se reutiliza o
            // crea la asignacion anual cliente/edicion correspondiente, y si
            // la asignacion no tenia usuario asignado, se le asigna el mismo
            // responsable del pedido. Nunca pisa una asignacion ya de otro
            // usuario (ver ClientAssignmentService::syncFromOrder).
            $assignments->syncFromOrder($order);

            // Fase 18.1: registrar el pago (uno o varios medios) en el mismo
            // paso de alta, para no obligar a crear el pedido y despues abrir
            // aparte la ventana de pagos. Mismo shape {payment_method_id,
            // amount} que ya usa OrderBulkController::pay en modo
            // 'fixed_lines'; se re-chequea el permiso real por si el
            // formulario llego a mostrarse indebidamente.
            if (! empty($data['payment_lines']) && Gate::allows('registerPayment', $order)) {
                foreach ($data['payment_lines'] as $line) {
                    $order->payments()->create([
                        'payment_method_id' => $line['payment_method_id'],
                        'amount' => $line['amount'],
                        'paid_at' => now(),
                        'registered_by' => $request->user()->id,
                    ]);
                }
            }

            return $order;
        });

        return redirect()->route('orders.index')->with('success', "Pedido #{$order->id} creado.");
    }

    /**
     * Pantalla de edicion de un pedido existente (Orders/Edit.vue): datos
     * generales + lineas (via OrderItemController) + pagos/retiro (via
     * OrderBulkController, reusado con un solo order_id para no duplicar logica).
     */
    public function edit(Request $request, Order $order): Response
    {
        Gate::authorize('update', $order);

        $user = $request->user();
        $order->load(['client', 'rover:id,name', 'year', 'items', 'payments.method', 'withdrawnBy:id,name']);

        return Inertia::render('Orders/Edit', [
            'order' => $order,
            'years' => Year::orderByDesc('year')->get(['id', 'year', 'label', 'is_active', 'portion_price', 'promo_unit_price', 'amount_for_promo']),
            'rovers' => $user->can('pedidos.asignar-rover')
                ? User::permission('pedidos.editar')->active()->orderBy('name')->get(['id', 'name'])
                : [],
            'paymentMethods' => PaymentMethod::where('is_active', true)->get(['id', 'name']),
            'canAssignRover' => $user->can('pedidos.asignar-rover'),
            'canRegisterPayment' => $user->can('pagos.registrar'),
            'canWithdraw' => $user->can('pedidos.retirar'),
            'canDelete' => $user->can('pedidos.eliminar'),
            'canExceptionalPrice' => $user->can('pedidos.precio-excepcional'),
            // Fase 18.1: mismo criterio que create(), ver docblock de store().
            'canChooseYear' => $user->can('anios.gestionar'),
            'authUserId' => $user->id,
        ]);
    }

    /**
     * Fase 7, seccion 8: advertencia de pedido duplicado. Devuelve los
     * pedidos NO cancelados que ya existen para un cliente en una edicion,
     * para que el frontend (New.vue, o el picker de Clientes/Pedidos) pueda
     * mostrar la advertencia ANTES de crear otro. Nunca bloquea la creacion:
     * solo informa (la decision de "crear igual" queda del lado del usuario,
     * ver seccion 8 del prompt de la fase). Respeta el mismo scope que
     * OrderController::index (un Rover sin 'pedidos.ver-todos' solo ve sus
     * propios pedidos existentes, para no filtrar informacion de otros).
     */
    public function checkExisting(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Order::class);

        $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'year_id' => ['required', 'integer', 'exists:years,id'],
        ]);

        $user = $request->user();
        $canViewAll = $user->can('pedidos.ver-todos');

        $orders = Order::query()
            ->where('client_id', $request->integer('client_id'))
            ->where('year_id', $request->integer('year_id'))
            ->where('status', '!=', 'cancelado')
            ->when(! $canViewAll, fn ($q) => $q->where('rover_id', $user->id))
            ->with('rover:id,name')
            ->get(['id', 'client_id', 'year_id', 'rover_id', 'total_portions', 'balance_due', 'status']);

        return response()->json(['orders' => $orders]);
    }

    /**
     * Datos generales del pedido (cliente, anio, rover, estado, observaciones).
     * Lineas se editan aparte via OrderItemController; pagos/retiro via
     * OrderBulkController.
     *
     * FIX DE SEGURIDAD (Fase 4): antes, 'rover_id' se guardaba con solo el
     * permiso generico 'pedidos.editar' (via OrderPolicy::update), permitiendo
     * que un rol como 'logistica' (que tiene 'pedidos.editar' pero NO
     * 'pedidos.asignar-rover') reasignara pedidos igual, coladoi por esta ruta
     * generica. Ahora se re-chequea explicitamente el permiso de asignacion
     * cuando el payload incluye rover_id.
     */
    public function update(UpdateOrderRequest $request, Order $order, PricingService $pricing, ClientAssignmentService $assignments): RedirectResponse
    {
        // Fase 19: una edicion que no es la activa es de solo lectura salvo
        // para 'anios.gestionar' (ver Year::isEditableBy). Se chequea contra
        // el year_id ACTUAL del pedido, no el que eventualmente llegue en el
        // payload (cambiar de edicion ya requiere 'anios.gestionar' aparte,
        // ver el bloque de abajo).
        Gate::authorize('mutate', $order->year);

        $data = $request->validated();

        // Fase 18.1: cambiar la edicion de un pedido ya creado es la misma
        // accion administrativa que elegirla al crearlo (ver store()): sin
        // 'anios.gestionar', se ignora silenciosamente cualquier year_id
        // distinto que llegue en el payload (el frontend ya no deberia
        // mostrar ese selector como editable para estos usuarios).
        if (array_key_exists('year_id', $data) && ! $request->user()->can('anios.gestionar')) {
            unset($data['year_id']);
        }

        // Fase 6A: si el payload trae rover_id y difiere del actual, se
        // re-chequea explicitamente contra la regla de autoasignacion vs
        // transferencia (ver OrderPolicy::assignRover). $targetRoverId puede
        // ser null (desasignar el pedido); en ese caso solo quien puede
        // transferir puede dejarlo sin responsable si ya tenia uno.
        $roverChanged = array_key_exists('rover_id', $data) && $data['rover_id'] !== $order->rover_id;

        if ($roverChanged) {
            Gate::authorize('assignRover', [$order, $data['rover_id'] !== null ? (int) $data['rover_id'] : null]);
        }

        $yearChanged = isset($data['year_id']) && (int) $data['year_id'] !== $order->year_id;

        // Si el pedido pasa a ser retira en mano, la direccion de entrega no
        // aplica: se limpia explicitamente (nunca se deja una direccion vieja
        // colgada en un pedido que ya no es delivery).
        if (array_key_exists('take_away', $data) && $data['take_away']) {
            $data['delivery_address'] = null;
        }

        $order->update([
            ...$data,
            'updated_by' => $request->user()->id,
        ]);

        if ($yearChanged) {
            $pricing->recalculatePricedLinesForOrder($order, $order->year()->firstOrFail());
        }

        // Fase 6A/7 (correccion): mantiene sincronizado el responsable
        // efectivo (y TODOS los demas pedidos del mismo cliente/edicion, ver
        // ClientAssignmentService::syncResponsibleForClientYear) cada vez que
        // se edita un pedido, sin importar si el campo que cambio fue
        // rover_id u otro (syncFromOrder ya lo hace de forma incondicional
        // cuando el pedido tiene rover).
        $assignments->syncFromOrder($order->fresh());

        return back()->with('success', "Pedido #{$order->id} actualizado.");
    }

    /**
     * Fase 7 (correccion 2), seccion 4: eliminacion de pedido. Es un SOFT
     * DELETE (Order usa SoftDeletes, ver migracion de orders): el pedido deja
     * de listarse, pero NO se borra fisicamente ni arrastra en cascada sus
     * `payments`/`order_items` (esas FKs tienen cascadeOnDelete a nivel de
     * base, pero eso solo dispararia con un DELETE SQL real; un soft delete
     * de Eloquent solo hace UPDATE de `deleted_at`, asi que pagos e items
     * quedan intactos e inalterados). Esto ya protegia la informacion
     * contable/historica correctamente; el bug reportado NO era de diseño de
     * borrado sino de permisos + manejo de errores (ver mas abajo).
     *
     * wantsJson() se soporta explicitamente (igual que store/update) porque
     * el frontend ahora llama a este endpoint con axios en vez de con una
     * navegacion Inertia, para poder mostrar un mensaje de error claro si
     * falla (antes fallaba en silencio, ver Orders/Edit.vue).
     */
    public function destroy(Request $request, Order $order): RedirectResponse|JsonResponse
    {
        Gate::authorize('delete', $order);
        Gate::authorize('mutate', $order->year);

        $order->delete();

        if ($request->wantsJson()) {
            return response()->json(['message' => "Pedido #{$order->id} eliminado."]);
        }

        return back()->with('success', "Pedido #{$order->id} eliminado.");
    }

    /**
     * FASE 4.1: endpoint dedicado para el caso comun de edicion ("cambiar la
     * cantidad de porciones"). Reemplaza tener que borrar y volver a crear
     * lineas a mano desde OrderItemController para el caso mas frecuente.
     * Responde JSON (se usa desde Orders/Edit.vue sin recargar la pagina).
     */
    public function updatePortions(UpdatePortionsRequest $request, Order $order, PricingService $pricing): JsonResponse
    {
        Gate::authorize('mutate', $order->year);

        $pricing->syncPortionsForOrder($order, $request->validated('portions'), $order->year, $request->user()->id);

        return response()->json([
            'order' => $order->fresh(['items', 'payments']),
        ]);
    }
}
