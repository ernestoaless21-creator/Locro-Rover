<?php

namespace App\Http\Requests;

use App\Models\Year;
use App\Services\PricingService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FASE 4.1 (simplificacion): el alta de pedido ya NO recibe un array generico
 * de lineas. Recibe 'portions' (cantidad de porciones de locro) y arma las
 * lineas de locro+salsas automaticamente en el Controller via
 * PricingService::syncPortionsForOrder. 'advanced_items' es opcional y
 * exclusivamente para las excepciones (regalo/personalizado) de "Opciones
 * avanzadas": nunca reemplaza la linea principal de locro, se suman aparte.
 */
class StoreOrderRequest extends FormRequest
{
    /**
     * Fase 5C: 'advanced_items' ya NO acepta 'regalo' (los regalos/donaciones
     * tienen su propio modulo independiente, ver Gift). El flujo normal de
     * pedidos solo admite la excepcion 'personalizado' (precio excepcional),
     * y solo si el usuario tiene el permiso 'pedidos.precio-excepcional'
     * (admin/jefe_logistica/logistica). Datos historicos con lineas tipo
     * 'regalo' ya guardadas en pedidos existentes NO se tocan por este
     * Request (solo aplica al ALTA de un pedido nuevo).
     */
    public function authorize(): bool
    {
        if (! $this->user()->can('create', \App\Models\Order::class)) {
            return false;
        }

        $items = $this->input('advanced_items', []);
        if (is_array($items)) {
            foreach ($items as $item) {
                if ((($item['type'] ?? null) === 'personalizado') && ! $this->user()->can('pedidos.precio-excepcional')) {
                    return false;
                }
            }
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'year_id' => ['required', 'integer', 'exists:years,id'],
            'rover_id' => ['nullable', 'integer', 'exists:users,id'],
            'take_away' => ['boolean'],
            'delivery_address' => ['nullable', 'string', 'max:255'],
            'observations' => ['nullable', 'string', 'max:2000'],

            'portions' => ['required', 'integer', 'min:1'],

            'advanced_items' => ['nullable', 'array'],
            'advanced_items.*.type' => ['required', 'string', 'in:personalizado'],
            'advanced_items.*.quantity' => ['required', 'integer', 'min:1'],
            'advanced_items.*.description' => ['nullable', 'string', 'max:255'],
            'advanced_items.*.custom_unit_price' => ['nullable', 'numeric', 'min:0'],

            // Fase 18.1: registrar el pago (uno o varios medios) en el mismo
            // alta del pedido. Opcional: un pedido puede crearse sin pagos,
            // igual que hoy. Mismo shape que BulkPayOrdersRequest 'lines'.
            'payment_lines' => ['nullable', 'array'],
            'payment_lines.*.payment_method_id' => ['required_with:payment_lines', 'integer', 'exists:payment_methods,id'],
            'payment_lines.*.amount' => ['required_with:payment_lines', 'numeric', 'min:0.01'],
        ];
    }

    /**
     * - 'personalizado' requiere precio (via PricingService::validateItemRules).
     * - Si el pedido es delivery (take_away = false), la direccion de entrega
     *   es obligatoria (snapshot propio del pedido). Si es retira en mano, no
     *   se pide.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $items = $this->input('advanced_items', []);
            if (is_array($items)) {
                $pricing = app(PricingService::class);

                foreach ($items as $index => $item) {
                    $errors = $pricing->validateItemRules(
                        product: 'locro',
                        type: $item['type'] ?? '',
                        customUnitPrice: isset($item['custom_unit_price']) ? (float) $item['custom_unit_price'] : null,
                        year: null,
                    );

                    foreach ($errors as $field => $message) {
                        $validator->errors()->add("advanced_items.{$index}.{$field}", $message);
                    }
                }
            }

            $isDelivery = ! $this->boolean('take_away');
            if ($isDelivery && trim((string) $this->input('delivery_address')) === '') {
                $validator->errors()->add('delivery_address', 'La direccion de entrega es obligatoria para pedidos con delivery.');
            }
        });
    }
}
