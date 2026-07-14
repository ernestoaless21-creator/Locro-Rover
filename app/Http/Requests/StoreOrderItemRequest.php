<?php

namespace App\Http\Requests;

use App\Services\PricingService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrderItemRequest extends FormRequest
{
    /**
     * Fase 5C: ya no se pueden crear NUEVAS lineas tipo 'regalo' desde el
     * formulario de pedidos (los regalos/donaciones tienen su propio modulo
     * independiente, ver Gift). Ademas, crear una linea 'personalizado'
     * (precio excepcional) requiere el permiso 'pedidos.precio-excepcional'.
     */
    public function authorize(): bool
    {
        // La autorizacion real (dueno del pedido / permiso) vive en Order::update,
        // porque agregar una linea es una forma de "editar" el pedido.
        if (! $this->user()->can('update', $this->route('order'))) {
            return false;
        }

        if ($this->input('type') === 'personalizado' && ! $this->user()->can('pedidos.precio-excepcional')) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'product' => ['required', 'string', 'in:locro,batata,salsas,pastelitos,otro'],
            'type' => ['required', 'string', 'in:normal,promocion,personalizado'],
            'quantity' => ['required', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:255'],
            'custom_unit_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $order = $this->route('order');
            $pricing = app(PricingService::class);

            $errors = $pricing->validateItemRules(
                product: $this->input('product', ''),
                type: $this->input('type', ''),
                customUnitPrice: $this->filled('custom_unit_price') ? (float) $this->input('custom_unit_price') : null,
                year: $order?->year,
            );

            foreach ($errors as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        });
    }
}
