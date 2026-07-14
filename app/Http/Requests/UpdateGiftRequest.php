<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('regalos.gestionar');
    }

    /**
     * 'year_id' deliberadamente NO es editable: cambiar de edicion un regalo
     * ya registrado moveria stock entre ediciones de forma confusa (igual
     * criterio que un pedido no cambia de cliente al editarse). Si hace
     * falta mover un regalo a otra edicion, se borra y se crea de nuevo.
     */
    public function rules(): array
    {
        return [
            'recipient_name' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
