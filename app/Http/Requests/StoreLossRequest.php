<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLossRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('perdidas.gestionar');
    }

    public function rules(): array
    {
        return [
            'year_id' => ['required', 'integer', 'exists:years,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
