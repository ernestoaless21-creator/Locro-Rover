<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClientObservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('client'));
    }

    public function rules(): array
    {
        return [
            'year_id' => ['required', 'integer', 'exists:years,id'],
            // Se permite string vacio: si el usuario borra todo el texto y presiona Enter,
            // se interpreta como "eliminar observacion" (se maneja en el Controller).
            'observation' => ['nullable', 'string', 'max:2000'],
            // idempotency key generado por el frontend (uuid) para evitar guardados
            // duplicados si Enter y blur disparan casi al mismo tiempo.
            'client_request_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
