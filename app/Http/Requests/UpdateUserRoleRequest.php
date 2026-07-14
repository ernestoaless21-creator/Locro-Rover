<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    public function rules(): array
    {
        return [
            // Los roles disponibles son siempre los que YA existen en la base
            // (creados por RolesAndPermissionsSeeder), nunca un nombre libre:
            // no se agrega creacion dinamica de roles (pedido explicitamente
            // que no se necesita por ahora).
            'role' => ['required', 'string', 'exists:roles,name'],
        ];
    }
}
