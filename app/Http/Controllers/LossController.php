<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLossRequest;
use App\Http\Requests\UpdateLossRequest;
use App\Models\Loss;
use App\Models\Year;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fase 5B. Porciones perdidas: registros independientes, NO son pedidos ni
 * ventas (no generan importe/saldo/pagos). Gateado enteramente por el
 * permiso 'perdidas.gestionar' (mismo criterio que GiftController: sin
 * Policy de instancia, sin scope por rover).
 */
class LossController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('perdidas.gestionar');

        $year = $request->filled('year_id')
            ? Year::findOrFail($request->get('year_id'))
            : Year::where('is_active', true)->firstOrFail();

        $losses = Loss::query()
            ->where('year_id', $year->id)
            ->with('createdBy:id,name')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Losses/Index', [
            'losses' => $losses,
            'year' => $year,
            'years' => Year::orderByDesc('year')->get(['id', 'year', 'label', 'is_active']),
            'totalPortions' => (int) $losses->sum('quantity'),
        ]);
    }

    public function store(StoreLossRequest $request): RedirectResponse|JsonResponse
    {
        Gate::authorize('mutate', Year::findOrFail($request->validated('year_id')));

        $loss = Loss::create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);

        if ($request->wantsJson()) {
            return response()->json(['loss' => $loss->load('createdBy:id,name')]);
        }

        return back()->with('success', "Perdida de {$loss->quantity} porcion(es) registrada.");
    }

    public function update(UpdateLossRequest $request, Loss $loss): RedirectResponse|JsonResponse
    {
        Gate::authorize('mutate', $loss->year);

        $loss->update($request->validated());

        if ($request->wantsJson()) {
            return response()->json(['loss' => $loss->fresh()->load('createdBy:id,name')]);
        }

        return back()->with('success', 'Perdida actualizada.');
    }

    public function destroy(Request $request, Loss $loss): RedirectResponse
    {
        Gate::authorize('perdidas.gestionar');
        Gate::authorize('mutate', $loss->year);

        $loss->delete();

        return back()->with('success', 'Perdida eliminada.');
    }
}
