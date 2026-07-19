<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RequiresBulkOrdersPermission;
use Illuminate\Foundation\Http\FormRequest;

class BulkWithdrawOrdersRequest extends FormRequest
{
    use RequiresBulkOrdersPermission;

    public function authorize(): bool
    {
        return $this->user()->can('pedidos.retirar') && $this->passesBulkOrdersGate();
    }

    public function rules(): array
    {
        return [
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer', 'exists:orders,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
