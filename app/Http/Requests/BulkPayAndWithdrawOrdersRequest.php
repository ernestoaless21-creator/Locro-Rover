<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RequiresBulkOrdersPermission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Fase 7, seccion 10: accion masiva principal "Cobrar y retirar
 * seleccionados". Requiere ambos permisos ('pagos.registrar' Y
 * 'pedidos.retirar'), ya que hace las dos cosas en una sola operacion.
 * El importe cobrado por pedido SIEMPRE es su balance_due actual (no se
 * acepta un monto libre aca): ver OrderBulkController::payAndWithdraw.
 */
class BulkPayAndWithdrawOrdersRequest extends FormRequest
{
    use RequiresBulkOrdersPermission;

    public function authorize(): bool
    {
        return $this->user()->can('pagos.registrar')
            && $this->user()->can('pedidos.retirar')
            && $this->passesBulkOrdersGate();
    }

    public function rules(): array
    {
        return [
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer', 'exists:orders,id'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'paid_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
