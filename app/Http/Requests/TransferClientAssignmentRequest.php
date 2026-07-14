<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Fase 6A, seccion 2/9: transferir una asignacion (tenga o no responsable
 * actual) a otro usuario. Solo Logistica/Jefe de Logistica/Admin (ver
 * ClientAssignmentPolicy::transfer).
 */
class TransferClientAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('transfer', $this->route('assignment'));
    }

    public function rules(): array
    {
        return [
            'assigned_user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
