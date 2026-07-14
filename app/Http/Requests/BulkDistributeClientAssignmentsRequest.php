<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkDistributeClientAssignmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('asignaciones.masivo');
    }

    public function rules(): array
    {
        return [
            'assignment_ids' => ['required', 'array', 'min:1'],
            'assignment_ids.*' => ['integer', 'exists:client_year_assignments,id'],
            // Quien ejecuta la accion elige explicitamente entre que usuarios
            // repartir (nunca se reparte automaticamente entre "todos").
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ];
    }
}
