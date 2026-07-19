<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RequiresBulkOrdersPermission;
use Illuminate\Foundation\Http\FormRequest;

class BulkAssignOrdersRequest extends FormRequest
{
    use RequiresBulkOrdersPermission;

    public function authorize(): bool
    {
        // Chequeo grueso aca (tiene el permiso en general); el chequeo FINO
        // (puede asignar ESTE pedido puntual) se re-valida por cada order_id
        // en el Controller, nunca confiando en la lista que mando el frontend.
        return $this->user()->can('pedidos.asignar-rover') && $this->passesBulkOrdersGate();
    }

    public function rules(): array
    {
        return [
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer', 'exists:orders,id'],
            'rover_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
