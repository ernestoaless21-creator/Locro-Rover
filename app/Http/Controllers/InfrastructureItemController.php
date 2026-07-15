<?php

namespace App\Http\Controllers;

use App\Models\InfrastructureItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class InfrastructureItemController extends Controller
{
    public function store(Request $request, string $team): RedirectResponse
    {
        Gate::authorize('infraestructura.inventario.gestionar');

        $data = $this->validateItem($request);

        InfrastructureItem::create([
            'name'        => trim($data['name']),
            'description' => $data['description'] ?? null,
        ]);

        return back()->with('success', 'Elemento creado.');
    }

    public function update(Request $request, string $team, InfrastructureItem $item): RedirectResponse
    {
        Gate::authorize('infraestructura.inventario.gestionar');

        $data = $this->validateItem($request, ignoreId: $item->id);

        $item->update([
            'name'        => trim($data['name']),
            'description' => $data['description'] ?? null,
            'is_active'   => $data['is_active'] ?? $item->is_active,
        ]);

        return back()->with('success', 'Elemento actualizado.');
    }

    private function validateItem(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active'   => ['sometimes', 'boolean'],
        ]);

        $duplicateQuery = InfrastructureItem::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))]);
        if ($ignoreId) {
            $duplicateQuery->where('id', '!=', $ignoreId);
        }

        if ($duplicateQuery->exists()) {
            throw ValidationException::withMessages([
                'name' => ['Ya existe un elemento con ese nombre en el catálogo.'],
            ]);
        }

        return $data;
    }
}
