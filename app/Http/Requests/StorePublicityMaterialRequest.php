<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePublicityMaterialRequest extends FormRequest
{
    /**
     * La autorización real (permiso + pertenencia al equipo) se hace en el
     * controller, exactamente igual que TeamDocumentController — este
     * Request se ocupa únicamente de validar.
     */
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
            'file'                  => ['required', 'file', 'max:51200'], // 50 MB (reels/video)
            'notes'                 => ['nullable', 'string', 'max:1000'],
            'material_date'         => ['nullable', 'date'],
            'year_id'               => ['nullable', 'integer', 'exists:years,id'],
        ];
    }
}
