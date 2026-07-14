<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGiftRequest;
use App\Http\Requests\UpdateGiftRequest;
use App\Models\Gift;
use App\Models\Year;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fase 5B. Porciones regaladas/donadas: registros independientes, NO son
 * pedidos (no tienen client_id, no generan importe/saldo/pagos). Gateado
 * enteramente por el permiso 'regalos.gestionar' (sin Policy de instancia,
 * mismo criterio que YearController: no hay scope por rover para este
 * dominio, es informacion de stock de la edicion completa).
 */
class GiftController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('regalos.gestionar');

        $year = $request->filled('year_id')
            ? Year::findOrFail($request->get('year_id'))
            : Year::where('is_active', true)->firstOrFail();

        $gifts = Gift::query()
            ->where('year_id', $year->id)
            ->with('createdBy:id,name')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Gifts/Index', [
            'gifts' => $gifts,
            'year' => $year,
            'years' => Year::orderByDesc('year')->get(['id', 'year', 'label', 'is_active']),
            'totalPortions' => (int) $gifts->sum('quantity'),
        ]);
    }

    public function store(StoreGiftRequest $request): RedirectResponse|JsonResponse
    {
        $gift = Gift::create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);

        if ($request->wantsJson()) {
            return response()->json(['gift' => $gift->load('createdBy:id,name')]);
        }

        return back()->with('success', "Regalo de {$gift->quantity} porcion(es) registrado.");
    }

    public function update(UpdateGiftRequest $request, Gift $gift): RedirectResponse|JsonResponse
    {
        $gift->update($request->validated());

        if ($request->wantsJson()) {
            return response()->json(['gift' => $gift->fresh()->load('createdBy:id,name')]);
        }

        return back()->with('success', 'Regalo actualizado.');
    }

    public function destroy(Request $request, Gift $gift): RedirectResponse
    {
        Gate::authorize('regalos.gestionar');

        $gift->delete();

        return back()->with('success', 'Regalo eliminado.');
    }
}
