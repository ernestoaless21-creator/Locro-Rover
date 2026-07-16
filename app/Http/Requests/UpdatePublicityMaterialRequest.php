<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePublicityMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'publicity_category_id' => ['required', 'integer', 'exists:publicity_categories,id'],
            'title'                 => ['required', 'string', 'max:255'],
            'description'           => ['nullable', 'string', 'max:1000'],
            'notes'                 => ['nullable', 'string', 'max:1000'],
            'material_date'         => ['nullable', 'date'],
            // Reemplazo de archivo opcional: si no se manda, el archivo actual queda intacto.
            'file'                  => ['nullable', 'file', 'max:51200'],
        ];
    }
}
