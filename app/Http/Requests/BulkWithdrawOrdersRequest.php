<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkWithdrawOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('pedidos.retirar');
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
