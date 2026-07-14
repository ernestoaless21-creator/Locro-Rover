<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('regalos.gestionar');
    }

    public function rules(): array
    {
        return [
            'year_id' => ['required', 'integer', 'exists:years,id'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
