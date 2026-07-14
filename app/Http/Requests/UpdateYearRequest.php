<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('parametros.gestionar');
    }

    /**
     * Para desactivar la promocion: mandar promo_unit_price=null y
     * amount_for_promo=null (ambos 'nullable', sin necesidad de una
     * migracion nueva, el esquema ya lo permite).
     *
     * 'recalculate_orders': decision EXPLICITA del usuario en cada guardado
     * (nunca default true) sobre si además de guardar los parametros, se
     * deben recalcular los pedidos ya existentes de esta edicion con los
     * nuevos valores. Ver YearController::update.
     */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:255'],
            'portion_price' => ['required', 'numeric', 'min:0'],
            'promo_unit_price' => ['nullable', 'numeric', 'min:0'],
            'amount_for_promo' => ['nullable', 'integer', 'min:1'],
            'made_portions' => ['nullable', 'integer', 'min:0'],
            'sale_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Relacion configurable de salsas (Fase 5B): "sauce_units_per_block"
            // salsas cada "sauce_portions_per_block" porciones. Ver
            // PricingService::calculateSauces para la formula completa.
            'sauce_portions_per_block' => ['nullable', 'integer', 'min:1'],
            'sauce_units_per_block' => ['nullable', 'integer', 'min:0'],
            // Fase 6A, seccion 12: metas de venta configurables por edicion.
            'sales_goal_global' => ['nullable', 'integer', 'min:1'],
            'sales_goal_individual_default' => ['nullable', 'integer', 'min:1'],
            'recalculate_orders' => ['sometimes', 'boolean'],
        ];
    }
}
