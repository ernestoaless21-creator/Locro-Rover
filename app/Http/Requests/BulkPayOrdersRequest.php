<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class BulkPayOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('pagos.registrar');
    }

    /**
     * Dos modos, ambos usando SIEMPRE la tabla payments real (nunca un booleano
     * 'mp' como la app anterior):
     *
     * - 'full_balance': cada pedido seleccionado recibe UN pago por su propio
     *   saldo pendiente actual (balance_due), con el medio de pago indicado.
     *   Util para "marcar todos estos como pagados en efectivo al retirar".
     *
     * - 'fixed_lines': se aplican una o mas lineas {payment_method_id, amount}
     *   IDENTICAS a CADA pedido seleccionado (ej: cada uno paga $10.000 en
     *   efectivo + $5.000 por transferencia). Permite pago parcial (si el monto
     *   es menor al saldo) registrando varios medios de pago a la vez.
     */
    public function rules(): array
    {
        return [
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer', 'exists:orders,id'],
            'mode' => ['required', 'string', 'in:full_balance,fixed_lines'],
            'payment_method_id' => ['required_if:mode,full_balance', 'nullable', 'integer', 'exists:payment_methods,id'],
            'lines' => ['required_if:mode,fixed_lines', 'nullable', 'array', 'min:1'],
            'lines.*.payment_method_id' => ['required_with:lines', 'integer', 'exists:payment_methods,id'],
            'lines.*.amount' => ['required_with:lines', 'numeric', 'min:0.01'],
            'paid_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Evita pagos negativos o en $0 (ya cubierto por min:0.01 arriba) y advierte
     * si en modo 'fixed_lines' el total de las lineas supera claramente el saldo
     * de ALGUNO de los pedidos seleccionados: no se bloquea (un sobrepago real
     * puede pasar y hay que poder registrarlo), pero se exige confirmacion
     * explicita del frontend via el flag 'confirm_overpayment' para que no sea
     * un error de tipeo silencioso.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('mode') !== 'fixed_lines' || ! is_array($this->input('lines'))) {
                return;
            }

            $totalPerOrder = collect($this->input('lines'))->sum('amount');
            if ($totalPerOrder <= 0) {
                return;
            }

            $orders = \App\Models\Order::whereIn('id', $this->input('order_ids', []))->get(['id', 'balance_due']);
            $anyOverpay = $orders->contains(fn ($o) => bccomp((string) $totalPerOrder, (string) $o->balance_due, 2) === 1);

            if ($anyOverpay && ! $this->boolean('confirm_overpayment')) {
                $validator->errors()->add(
                    'lines',
                    'El monto ingresado supera el saldo pendiente de al menos uno de los pedidos seleccionados. Confirma para continuar de todos modos.'
                );
            }
        });
    }
}
