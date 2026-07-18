<?php

namespace App\Http\Requests;

use App\Services\Import\ImportFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnalyzeImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('historico.importar');
    }

    public function rules(): array
    {
        return [
            // year_id no viaja aca: analyzeFile() no lo necesita (el
            // matching de clientes/rovers es independiente de la edicion).
            // Se pide recien en confirm(), que es donde realmente se usa.
            'file' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
            'format' => ['nullable', 'string', Rule::in(array_map(fn (ImportFormat $f) => $f->value, ImportFormat::cases()))],
        ];
    }
}
