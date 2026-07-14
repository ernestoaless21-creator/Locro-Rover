<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLossRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('perdidas.gestionar');
    }

    /**
     * 'year_id' deliberadamente NO es editable, mismo criterio que
     * UpdateGiftRequest.
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
