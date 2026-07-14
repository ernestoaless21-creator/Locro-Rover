<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class GenerateAssignmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('asignaciones.generar');
    }

    public function rules(): array
    {
        return [
            'from_year_id' => ['required', 'integer', 'exists:years,id'],
            'to_year_id' => ['required', 'integer', 'exists:years,id', 'different:from_year_id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('from_year_id') === $this->input('to_year_id')) {
                $validator->errors()->add('to_year_id', 'La edicion destino debe ser distinta de la edicion origen.');
            }
        });
    }
}
