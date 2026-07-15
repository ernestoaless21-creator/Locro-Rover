<?php

namespace App\Http\Controllers;

use App\Models\PurchaseProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PurchaseProductController extends Controller
{
    public function store(Request $request, string $team): RedirectResponse
    {
        Gate::authorize('compras.planificacion.gestionar');

        $data = $this->validateProduct($request);

        PurchaseProduct::create([
            'purchase_category_id' => $data['purchase_category_id'] ?? null,
            'name'                 => trim($data['name']),
            'unit'                 => $data['unit'] ?? null,
            'notes'                => $data['notes'] ?? null,
        ]);

        return back()->with('success', 'Producto creado.');
    }

    public function update(Request $request, string $team, PurchaseProduct $product): RedirectResponse
    {
        Gate::authorize('compras.planificacion.gestionar');

        $data = $this->validateProduct($request, ignoreId: $product->id);

        $product->update([
            'purchase_category_id' => $data['purchase_category_id'] ?? null,
            'name'                 => trim($data['name']),
            'unit'                 => $data['unit'] ?? null,
            'notes'                => $data['notes'] ?? null,
            'is_active'            => $data['is_active'] ?? $product->is_active,
        ]);

        return back()->with('success', 'Producto actualizado.');
    }

    private function validateProduct(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'purchase_category_id' => ['nullable', 'integer', 'exists:purchase_categories,id'],
            'name'                 => ['required', 'string', 'max:255'],
            'unit'                 => ['nullable', 'string', 'max:30'],
            'notes'                => ['nullable', 'string'],
            'is_active'            => ['sometimes', 'boolean'],
        ]);

        $duplicateQuery = PurchaseProduct::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))]);
        if ($ignoreId) {
            $duplicateQuery->where('id', '!=', $ignoreId);
        }

        if ($duplicateQuery->exists()) {
            throw ValidationException::withMessages([
                'name' => ['Ya existe un producto con ese nombre en el catálogo.'],
            ]);
        }

        return $data;
    }
}
