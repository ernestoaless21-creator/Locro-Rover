<?php

namespace App\Http\Controllers;

use App\Models\PurchaseCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PurchaseCategoryController extends Controller
{
    public function store(Request $request, string $team): RedirectResponse
    {
        Gate::authorize('compras.planificacion.gestionar');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $duplicate = PurchaseCategory::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])->exists();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => ['Ya existe una categoría con ese nombre.'],
            ]);
        }

        PurchaseCategory::create(['name' => trim($data['name'])]);

        return back()->with('success', 'Categoría creada.');
    }
}
