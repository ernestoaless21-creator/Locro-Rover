<?php

namespace App\Http\Controllers;

use App\Models\PurchasePlanItem;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(Request $request, string $team): Response
    {
        Gate::authorize('compras.planificacion.ver');

        // Cuánto se le compró realmente a cada proveedor, en cualquier
        // edición (memoria histórica simple, sin estadísticas avanzadas).
        $purchasedTotals = PurchasePlanItem::whereNotNull('actual_supplier_id')
            ->selectRaw('actual_supplier_id, COUNT(*) as purchase_count, SUM(actual_total_price) as total_spent')
            ->groupBy('actual_supplier_id')
            ->get()
            ->keyBy('actual_supplier_id');

        $suppliers = Supplier::orderBy('name')->get()->map(fn (Supplier $s) => [
            ...$s->toArray(),
            'purchase_count' => $purchasedTotals[$s->id]->purchase_count ?? 0,
            'total_spent'    => $purchasedTotals[$s->id]->total_spent ?? null,
        ]);

        return Inertia::render('Suppliers/Index', [
            'team'      => $team,
            'suppliers' => $suppliers,
            'canManage' => $request->user()->can('proveedores.gestionar'),
        ]);
    }

    public function store(Request $request, string $team): RedirectResponse
    {
        Gate::authorize('proveedores.gestionar');

        $data = $this->validateSupplier($request);

        Supplier::create($data);

        return back()->with('success', 'Proveedor creado.');
    }

    public function update(Request $request, string $team, Supplier $supplier): RedirectResponse
    {
        Gate::authorize('proveedores.gestionar');

        $data = $this->validateSupplier($request);
        $data['is_active'] = $request->boolean('is_active', $supplier->is_active);

        $supplier->update($data);

        return back()->with('success', 'Proveedor actualizado.');
    }

    private function validateSupplier(Request $request): array
    {
        $data = $request->validate([
            'name'    => ['required', 'string', 'max:255'],
            'phone'   => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes'   => ['nullable', 'string'],
        ]);

        $duplicate = Supplier::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])
            ->when($request->route('supplier'), fn ($q, $supplier) => $q->where('id', '!=', $supplier->id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => ['Ya existe un proveedor con ese nombre.'],
            ]);
        }

        return $data;
    }
}
