<?php

namespace App\Http\Requests;

use App\Services\PricingService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderItemRequest extends FormRequest
{
    /**
     * 'regalo' se mantiene como tipo VALIDO aca (a diferencia de
     * StoreOrderItemRequest) solo para permitir editar/guardar sin error
     * lineas 'regalo' historicas que ya existieran en un pedido antes del
     * modulo Gift; no habilita crear una linea nueva (eso es Store, no
     * Update). Editar o guardar una linea 'personalizado' (precio
     * excepcional) requiere el permiso 'pedidos.precio-excepcional'.
     */
    public function authorize(): bool
    {
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
            'type' => ['required', 'string', 'in:normal,regalo,promocion,personalizado'],
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
