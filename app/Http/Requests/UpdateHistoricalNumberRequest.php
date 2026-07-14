<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Fase 6A, seccion 5. Solo Logistica/Jefe de Logistica/Admin pueden
 * gestionar el numero historico permanente del cliente (ver
 * ClientPolicy::manageHistoricalNumber). Nullable: se puede dejar un
 * cliente sin numero historico sin inventar uno.
 */
class UpdateHistoricalNumberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageHistoricalNumber', \App\Models\Client::class);
    }

    public function rules(): array
    {
        return [
            'historical_number' => [
                'nullable', 'integer', 'min:1',
                'unique:clients,historical_number,'.$this->route('client')?->id,
            ],
        ];
    }
}
