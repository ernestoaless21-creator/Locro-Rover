<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkAssignClientAssignmentsRequest extends FormRequest
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
            'assigned_user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
