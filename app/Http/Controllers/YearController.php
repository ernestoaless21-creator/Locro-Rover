<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateYearRequest;
use App\Models\Year;
use App\Services\PricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class YearController extends Controller
{
    /**
     * FASE 4.1: pantalla de configuracion de precios/promocion/datos de la
     * edicion. Solo para 'parametros.gestionar'. Permite crear una nueva
     * edicion y activarla (reusa store()/activate(), ya gateados con
     * 'anios.gestionar' - ver nota en update() sobre por que se dejo asi).
     */
    public function parameters(): Response
    {
        Gate::authorize('parametros.gestionar');

        return Inertia::render('Years/Parameters', [
            'years' => Year::orderByDesc('year')->get(),
        ]);
    }

    /**
     * Actualiza los parametros de precio/promocion/datos de UNA edicion.
     *
     * INTEGRIDAD HISTORICA: el recalculo de pedidos existentes NUNCA ocurre
     * por defecto. Solo pasa si el usuario elige explicitamente "Guardar y
     * recalcular pedidos" (recalculate_orders=true) cada vez que guarda.
     * "Guardar sin recalcular" (el default) deja los pedidos ya persistidos
     * exactamente como estaban, tal como funcionaba hasta ahora.
     *
     * Guardar los parametros del Year y (opcionalmente) recalcular todos sus
     * pedidos se hace en UNA sola transaccion: si el recalculo fallara a
     * mitad de camino, tambien se revierte el cambio de parametros, para
     * nunca dejar un estado a medias (parametros nuevos pero pedidos viejos
     * parcialmente recalculados).
     */
    public function update(UpdateYearRequest $request, Year $year, PricingService $pricing): RedirectResponse
    {
        Gate::authorize('mutate', $year);

        $data = $request->validated();
        $shouldRecalculate = (bool) ($data['recalculate_orders'] ?? false);
        unset($data['recalculate_orders']); // no es una columna de years, nunca se manda a Year::update()

        $updatedOrders = DB::transaction(function () use ($year, $data, $shouldRecalculate, $pricing) {
            $year->update($data);

            return $shouldRecalculate ? $pricing->recalculateAllOrdersForYear($year->fresh()) : null;
        });

        $message = "Parametros de la edicion {$year->year} actualizados.";
        if ($updatedOrders !== null) {
            $message .= " Se recalcularon {$updatedOrders} pedido(s) de esta edicion (los pagos registrados no se modificaron).";
        }

        return back()->with('success', $message);
    }

    /**
     * Marca un anio como el activo globalmente (selector de anio/edicion).
     * Redirige de vuelta a donde estaba el usuario para que la pagina se
     * recargue con los datos del nuevo anio (Inertia hace esto automatico
     * via el prop compartido 'currentYear', ver HandleInertiaRequests).
     */
    public function activate(Year $year): RedirectResponse
    {
        Gate::authorize('anios.gestionar');

        $year->activate();

        return back()->with('success', "Edicion {$year->year} activada.");
    }

    /**
     * Crea una nueva edicion/anio. Se mantiene gateado con 'anios.gestionar'
     * (permiso ya existente desde Fase 2) en vez de cambiarlo a
     * 'parametros.gestionar': el usuario acepto explicitamente que el
     * permiso "especifico ya existente" siga aplicando aca, solo la
     * ACTUALIZACION de parametros (update(), arriba) usa 'parametros.gestionar'.
     * En la practica ambos permisos los tienen los mismos roles hoy.
     */
    public function store(): RedirectResponse
    {
        Gate::authorize('anios.gestionar');

        $data = request()->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100', 'unique:years,year'],
            'label' => ['nullable', 'string', 'max:255'],
            'portion_price' => ['required', 'numeric', 'min:0'],
            'promo_unit_price' => ['nullable', 'numeric', 'min:0'],
            'amount_for_promo' => ['nullable', 'integer', 'min:1'],
            'made_portions' => ['nullable', 'integer', 'min:0'],
            'sale_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'event_type' => ['required', 'string', 'in:locro,pastelitos,otro'],
            // Opcionales al crear: si no se mandan, la columna usa su default
            // de migracion (2/1 = "1 salsa cada 2 porciones", regla anterior).
            'sauce_portions_per_block' => ['nullable', 'integer', 'min:1'],
            'sauce_units_per_block' => ['nullable', 'integer', 'min:0'],
            'sales_goal_global' => ['nullable', 'integer', 'min:1'],
            'sales_goal_individual_default' => ['nullable', 'integer', 'min:1'],
        ]);

        $year = Year::create($data);

        return back()->with('success', "Edicion {$year->year} creada.");
    }
}
