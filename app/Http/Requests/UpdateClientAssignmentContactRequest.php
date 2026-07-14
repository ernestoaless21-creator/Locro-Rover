<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Fase 6A. Actualiza SOLO el seguimiento de una asignacion (estado de
 * contacto + observaciones). Deliberadamente NO acepta 'assigned_user_id':
 * eso se cambia unicamente via selfAssign/transfer (ver
 * ClientAssignmentController), nunca por esta via generica, para cumplir la
 * regla explicita de que actualizar el seguimiento NUNCA transfiere la
 * propiedad del cliente.
 */
class UpdateClientAssignmentContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('updateContact', $this->route('assignment'));
    }

    public function rules(): array
    {
        return [
            'contact_status' => ['required', 'string', 'in:pendiente,no_respondio,volver_a_llamar,no_interesado,interesado,pedido_realizado'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Si el usuario registra que hizo el contacto ahora mismo.
            'mark_contacted_now' => ['sometimes', 'boolean'],
        ];
    }
}
