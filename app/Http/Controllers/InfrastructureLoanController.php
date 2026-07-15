<?php

namespace App\Http\Controllers;

use App\Models\InfrastructureLoan;
use App\Models\Year;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InfrastructureLoanController extends Controller
{
    public function store(Request $request, string $team): RedirectResponse
    {
        Gate::authorize('infraestructura.inventario.gestionar');

        $data = $this->validateLoan($request, requireItem: true);

        $year = $request->filled('year_id')
            ? Year::findOrFail($request->year_id)
            : Year::where('is_active', true)->firstOrFail();

        InfrastructureLoan::create([
            ...$data,
            'year_id'    => $year->id,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Préstamo registrado.');
    }

    public function update(Request $request, string $team, InfrastructureLoan $loan): RedirectResponse
    {
        Gate::authorize('infraestructura.inventario.gestionar');

        $data = $this->validateLoan($request);

        $loan->update($data);

        return back()->with('success', 'Préstamo actualizado.');
    }

    public function destroy(string $team, InfrastructureLoan $loan): RedirectResponse
    {
        Gate::authorize('infraestructura.inventario.gestionar');

        $loan->delete();

        return back()->with('success', 'Préstamo eliminado.');
    }

    /**
     * Cambia el estado de devolución. Separado de update() a propósito
     * (mismo criterio que ScheduleActivity en Fase 13): marcar como devuelto
     * nunca exige conocer la fecha exacta, y puede llamarse de nuevo para
     * corregir la fecha sin cambiar el estado.
     */
    public function updateStatus(Request $request, string $team, InfrastructureLoan $loan): RedirectResponse
    {
        Gate::authorize('infraestructura.inventario.gestionar');

        $data = $request->validate([
            'status'      => ['required', 'in:pending,returned'],
            'returned_at' => ['nullable', 'date'],
        ]);

        $loan->update([
            'status'      => $data['status'],
            'returned_at' => $data['status'] === 'returned' ? ($data['returned_at'] ?? $loan->returned_at) : null,
        ]);

        return back();
    }

    private function validateLoan(Request $request, bool $requireItem = false): array
    {
        $rules = [
            'quantity' => ['required', 'integer', 'min:1'],
            'lender'   => ['required', 'string', 'max:255'],
            'notes'    => ['nullable', 'string'],
        ];

        if ($requireItem) {
            $rules['infrastructure_item_id'] = ['required', 'integer', 'exists:infrastructure_items,id'];
        }

        return $request->validate($rules);
    }
}
