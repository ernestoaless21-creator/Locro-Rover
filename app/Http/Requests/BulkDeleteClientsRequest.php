<?php

namespace App\Http\Requests;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;

class BulkDeleteClientsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('bulkDelete', Client::class);
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:clients,id'],
        ];
    }
}
