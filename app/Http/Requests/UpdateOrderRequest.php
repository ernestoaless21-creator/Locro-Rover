<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('order'));
    }

    public function rules(): array
    {
        return [
            'client_id' => ['sometimes', 'integer', 'exists:clients,id'],
            'year_id' => ['sometimes', 'integer', 'exists:years,id'],
            'rover_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['sometimes', 'string', 'in:pendiente,confirmado,cancelado'],
            'take_away' => ['sometimes', 'boolean'],
            'delivery_address' => ['nullable', 'string', 'max:255'],
            'observations' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Si el pedido (con este update aplicado) queda como delivery, la
     * direccion de entrega es obligatoria. Se considera tanto lo que llega
     * en el payload como, si no llega, el valor ya persistido en el pedido
     * (para no exigir reenviar 'delivery_address' en updates que no tocan
     * take_away/direccion, ej. solo cambiar el estado).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $order = $this->route('order');

            $takeAway = $this->has('take_away')
                ? $this->boolean('take_away')
                : (bool) ($order?->take_away ?? true);

            if ($takeAway) {
                return;
            }

            $address = $this->has('delivery_address')
                ? trim((string) $this->input('delivery_address'))
                : trim((string) ($order?->delivery_address ?? ''));

            if ($address === '') {
                $validator->errors()->add('delivery_address', 'La direccion de entrega es obligatoria para pedidos con delivery.');
            }
        });
    }
}
