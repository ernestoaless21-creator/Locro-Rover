<?php

namespace App\Http\Requests;

use App\Services\Import\ImportFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('historico.importar');
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'uuid'],
            'year_id' => ['required', 'integer', 'exists:years,id'],
            'format' => ['required', 'string', Rule::in(array_map(fn (ImportFormat $f) => $f->value, ImportFormat::cases()))],
            'rover_overrides' => ['nullable', 'array'],
            'rover_overrides.*' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
