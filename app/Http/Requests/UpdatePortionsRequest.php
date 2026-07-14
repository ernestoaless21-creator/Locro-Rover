<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePortionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('order'));
    }

    public function rules(): array
    {
        return [
            // 0 esta permitido: vacia el pedido de lineas de locro/salsas
            // (ver PricingService::syncPortionsForOrder). No negativo.
            'portions' => ['required', 'integer', 'min:0'],
        ];
    }
}
