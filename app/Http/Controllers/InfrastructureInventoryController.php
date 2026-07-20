<?php

namespace App\Http\Controllers;

use App\Models\InfrastructureInventoryItem;
use App\Models\InfrastructureItem;
use App\Models\InfrastructureLoan;
use App\Models\Year;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InfrastructureInventoryController extends Controller
{
    public function index(Request $request, string $team): Response
    {
        Gate::authorize('infraestructura.inventario.ver');

        $year = $request->filled('year_id')
            ? Year::findOrFail($request->year_id)
            : Year::where('is_active', true)->firstOrFail();

        $inventoryItems = InfrastructureInventoryItem::with('item')
            ->where('year_id', $year->id)
            ->get()
            ->sortBy(fn (InfrastructureInventoryItem $inv) => $inv->item->name)
            ->values();

        $loans = InfrastructureLoan::with('item')
            ->where('year_id', $year->id)
            ->get()
            ->sortBy(fn (InfrastructureLoan $loan) => $loan->item->name)
            ->values();

        $activeLoansByItem = $loans
            ->where('status', 'pending')
            ->groupBy('infrastructure_item_id')
            ->map(fn ($group) => (int) $group->sum('quantity'));

        $inventoryRows = $inventoryItems->map(function (InfrastructureInventoryItem $inv) use ($activeLoansByItem) {
            $activeLoans = $activeLoansByItem[$inv->infrastructure_item_id] ?? 0;
            $ownUseful = $inv->own_useful_quantity;
            $totalUseful = $ownUseful + $activeLoans;
            $needed = $inv->needed_quantity;

            if ($totalUseful > $needed) {
                $status = 'surplus';
                $diff = $totalUseful - $needed;
            } elseif ($totalUseful < $needed) {
                $status = 'missing';
                $diff = $needed - $totalUseful;
            } else {
                $status = 'complete';
                $diff = 0;
            }

            return [
                'id' => $inv->id,
                'infrastructure_item_id' => $inv->infrastructure_item_id,
                'item' => $inv->item,
                'needed_quantity' => $needed,
                'own_available_quantity' => $inv->own_available_quantity,
                'own_to_repair_quantity' => $inv->own_to_repair_quantity,
                'own_useful_quantity' => $ownUseful,
                'active_loans_quantity' => $activeLoans,
                'total_useful_quantity' => $totalUseful,
                'status' => $status,
                'diff_quantity' => $diff,
                'notes' => $inv->notes,
            ];
        })->values();

        $loanSummary = [
            'active_count' => $loans->where('status', 'pending')->count(),
            'active_units' => (int) $loans->where('status', 'pending')->sum('quantity'),
            'pending_lenders' => $loans->where('status', 'pending')->pluck('lender')->unique()->count(),
            'returned_count' => $loans->where('status', 'returned')->count(),
        ];

        return Inertia::render('Infrastructure/Index', [
            'team' => $team,
            'year' => $year->toBasicArray(),
            'inventoryRows' => $inventoryRows,
            'loans' => $loans,
            'loanSummary' => $loanSummary,
            'items' => InfrastructureItem::orderBy('name')->get(),
            'canManage' => $request->user()->can('infraestructura.inventario.gestionar'),
        ]);
    }

    /**
     * Crea una fila de inventario para esta edición. Acepta un elemento ya
     * existente (infrastructure_item_id) o los datos para crear uno nuevo en
     * el mismo request (new_item_name), igual que el flujo de "agregar
     * producto" de Compras.
     */
    public function store(Request $request, string $team): RedirectResponse
    {
        Gate::authorize('infraestructura.inventario.gestionar');

        $data = $this->validateInventory($request, requireItem: true);

        $year = $request->filled('year_id')
            ? Year::findOrFail($request->year_id)
            : Year::where('is_active', true)->firstOrFail();
        Gate::authorize('mutate', $year);

        $itemId = $data['infrastructure_item_id'] ?? $this->createItemFromRequest($request);

        $exists = InfrastructureInventoryItem::where('year_id', $year->id)
            ->where('infrastructure_item_id', $itemId)
            ->exists();

        if ($exists) {
            return back()->withErrors(['infrastructure_item_id' => 'Ese elemento ya está en el inventario de esta edición.']);
        }

        unset($data['infrastructure_item_id']);

        InfrastructureInventoryItem::create([
            ...$data,
            'infrastructure_item_id' => $itemId,
            'year_id' => $year->id,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Elemento agregado al inventario.');
    }

    public function update(Request $request, string $team, InfrastructureInventoryItem $inventory): RedirectResponse
    {
        Gate::authorize('infraestructura.inventario.gestionar');
        Gate::authorize('mutate', $inventory->year);

        $data = $this->validateInventory($request, inventory: $inventory);

        $inventory->update($data);

        return back()->with('success', 'Inventario actualizado.');
    }

    public function destroy(string $team, InfrastructureInventoryItem $inventory): RedirectResponse
    {
        Gate::authorize('infraestructura.inventario.gestionar');
        Gate::authorize('mutate', $inventory->year);

        $inventory->delete();

        return back()->with('success', 'Elemento quitado de esta edición.');
    }

    private function createItemFromRequest(Request $request): int
    {
        $data = $request->validate([
            'new_item_name' => ['required', 'string', 'max:255'],
        ]);

        $duplicate = InfrastructureItem::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['new_item_name']))])->exists();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'new_item_name' => ['Ya existe un elemento con ese nombre en el catálogo.'],
            ]);
        }

        $item = InfrastructureItem::create(['name' => trim($data['new_item_name'])]);

        return $item->id;
    }

    private function validateInventory(Request $request, bool $requireItem = false, ?InfrastructureInventoryItem $inventory = null): array
    {
        $rules = [
            'needed_quantity' => ['nullable', 'integer', 'min:0'],
            'own_available_quantity' => ['nullable', 'integer', 'min:0'],
            'own_to_repair_quantity' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];

        // infrastructure_item_id solo se fija al crear: cambiar de elemento
        // una fila ya existente rompería la unicidad (año, elemento) y el
        // historial de a qué elemento correspondía cada carga.
        if ($requireItem) {
            $rules['infrastructure_item_id'] = ['required_without:new_item_name', 'nullable', 'integer', 'exists:infrastructure_items,id'];
        }

        $data = $request->validate($rules);

        // Un campo de cantidad vacío significa "0", nunca NULL: las columnas
        // son NOT NULL con default 0 y 'nullable' en la regla solo permite
        // que el campo llegue vacío desde el form, no que se guarde como
        // NULL. Se normaliza únicamente lo que vino en el request, para no
        // pisar campos no enviados en una actualización parcial.
        foreach (['needed_quantity', 'own_available_quantity', 'own_to_repair_quantity'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === null) {
                $data[$field] = 0;
            }
        }

        $available = $data['own_available_quantity'] ?? $inventory?->own_available_quantity ?? 0;
        $toRepair = $data['own_to_repair_quantity'] ?? $inventory?->own_to_repair_quantity ?? 0;

        if ($toRepair > $available) {
            throw ValidationException::withMessages([
                'own_to_repair_quantity' => ['La cantidad en reparación no puede superar la cantidad nuestra disponible.'],
            ]);
        }

        return $data;
    }
}
