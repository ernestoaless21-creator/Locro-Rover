<?php

namespace App\Http\Controllers;

use App\Models\PurchaseCategory;
use App\Models\PurchasePlanItem;
use App\Models\PurchaseProduct;
use App\Models\Supplier;
use App\Models\Year;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PurchasePlanItemController extends Controller
{
    public function index(Request $request, string $team): Response
    {
        Gate::authorize('compras.planificacion.ver');

        $year = $request->filled('year_id')
            ? Year::findOrFail($request->year_id)
            : Year::where('is_active', true)->firstOrFail();

        $items = PurchasePlanItem::with(['product.category', 'plannedSupplier', 'actualSupplier'])
            ->where('year_id', $year->id)
            ->get()
            ->sortBy(fn (PurchasePlanItem $item) => $item->product->name)
            ->values();

        // Un ítem cuenta como "compra realizada sin precio real" solo cuando
        // hay evidencia razonable de que la compra ya ocurrió (cantidad real
        // o proveedor real cargados) pero falta el precio real. Un producto
        // simplemente planificado (sin ningún dato de ejecución todavía) no
        // es una compra pendiente de precio, es una compra que ni siquiera
        // se hizo.
        $itemsWithoutRealPrice = $items
            ->filter(fn (PurchasePlanItem $item) => (
                ($item->actual_quantity !== null || $item->actual_supplier_id !== null)
                && $item->actual_total_price === null
            ))
            ->count();

        return Inertia::render('Purchases/Index', [
            'team'       => $team,
            'year'       => $year->only('id', 'year', 'label'),
            'items'      => $items,
            'products'   => PurchaseProduct::with('category')->orderBy('name')->get(),
            'categories' => PurchaseCategory::orderBy('name')->get(),
            'suppliers'  => Supplier::where('is_active', true)->orderBy('name')->get(),
            'totals'     => [
                'estimated'                => (float) $items->sum('estimated_total_price'),
                'real'                     => (float) $items->sum('actual_total_price'),
                'items_without_real_price' => $itemsWithoutRealPrice,
                'items_count'              => $items->count(),
            ],
            'canManage' => $request->user()->can('compras.planificacion.gestionar'),
        ]);
    }

    /**
     * Crea un ítem de planificación. Acepta un producto ya existente
     * (purchase_product_id) o los datos para crear uno nuevo en el mismo
     * request (new_product_name [+ new_product_category_id]), para permitir
     * carga rápida sin un paso previo separado de "crear producto".
     */
    public function store(Request $request, string $team): RedirectResponse
    {
        Gate::authorize('compras.planificacion.gestionar');

        $data = $this->validateItem($request, requireProduct: true);

        $year = $request->filled('year_id')
            ? Year::findOrFail($request->year_id)
            : Year::where('is_active', true)->firstOrFail();

        $productId = $data['purchase_product_id'] ?? $this->createProductFromRequest($request);

        $exists = PurchasePlanItem::where('year_id', $year->id)
            ->where('purchase_product_id', $productId)
            ->exists();

        if ($exists) {
            return back()->withErrors(['purchase_product_id' => 'Ese producto ya está en la planificación de esta edición.']);
        }

        unset($data['purchase_product_id']);

        PurchasePlanItem::create([
            ...$data,
            'purchase_product_id' => $productId,
            'year_id'             => $year->id,
            'created_by'          => $request->user()->id,
        ]);

        return back()->with('success', 'Ítem agregado a la planificación.');
    }

    public function update(Request $request, string $team, PurchasePlanItem $item): RedirectResponse
    {
        Gate::authorize('compras.planificacion.gestionar');

        $data = $this->validateItem($request);

        $item->update($data);

        return back()->with('success', 'Ítem actualizado.');
    }

    public function destroy(string $team, PurchasePlanItem $item): RedirectResponse
    {
        Gate::authorize('compras.planificacion.gestionar');

        $item->delete();

        return back()->with('success', 'Ítem eliminado de la planificación.');
    }

    private function createProductFromRequest(Request $request): int
    {
        $data = $request->validate([
            'new_product_name'        => ['required', 'string', 'max:255'],
            'new_product_category_id' => ['nullable', 'integer', 'exists:purchase_categories,id'],
        ]);

        $duplicate = PurchaseProduct::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['new_product_name']))])->exists();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'new_product_name' => ['Ya existe un producto con ese nombre en el catálogo.'],
            ]);
        }

        $product = PurchaseProduct::create([
            'purchase_category_id' => $data['new_product_category_id'] ?? null,
            'name'                 => trim($data['new_product_name']),
            'unit'                 => $request->input('unit'),
        ]);

        return $product->id;
    }

    private function validateItem(Request $request, bool $requireProduct = false): array
    {
        $rules = [
            'qty_1000'               => ['nullable', 'numeric', 'min:0'],
            'qty_1500'               => ['nullable', 'numeric', 'min:0'],
            'unit'                   => ['nullable', 'string', 'max:30'],
            'estimated_total_price'  => ['nullable', 'numeric', 'min:0'],
            'planned_supplier_id'    => ['nullable', 'integer', 'exists:suppliers,id'],
            'actual_quantity'        => ['nullable', 'numeric', 'min:0'],
            'actual_total_price'     => ['nullable', 'numeric', 'min:0'],
            'actual_supplier_id'     => ['nullable', 'integer', 'exists:suppliers,id'],
            'notes'                  => ['nullable', 'string'],
        ];

        // purchase_product_id solo se fija al crear: cambiar de producto un
        // ítem ya existente rompería la unicidad (año, producto) y el
        // historial de a qué producto correspondía cada carga.
        if ($requireProduct) {
            $rules['purchase_product_id'] = ['required_without:new_product_name', 'nullable', 'integer', 'exists:purchase_products,id'];
        }

        return $request->validate($rules);
    }
}
