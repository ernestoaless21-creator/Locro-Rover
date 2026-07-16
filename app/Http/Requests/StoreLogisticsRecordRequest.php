<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLogisticsRecordRequest extends FormRequest
{
    /**
     * La autorización real (permiso + pertenencia al equipo) se hace en el
     * controller, exactamente igual que TeamDocumentController/
     * PublicityMaterialController — este Request se ocupa únicamente de validar.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'logistics_category_id' => ['required', 'integer', 'exists:logistics_categories,id'],
            'title'                 => ['required', 'string', 'max:255'],
            'description'           => ['nullable', 'string', 'max:1000'],
            // Finalidad: contexto operativo corto (no reemplaza description).
            'purpose'               => ['nullable', 'string', 'max:255'],
            'file'                  => ['required', 'file', 'max:51200'], // 50 MB
            'notes'                 => ['nullable', 'string', 'max:1000'],
            'record_date'           => ['nullable', 'date'],
            'year_id'               => ['nullable', 'integer', 'exists:years,id'],
        ];
    }
}
