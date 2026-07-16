<?php

namespace App\Http\Controllers;

use App\Models\LogisticsCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class LogisticsCategoryController extends Controller
{
    public function store(Request $request, string $team): RedirectResponse
    {
        Gate::authorize('tareas.gestionar-propio-equipo');
        $this->authorizeTeamAccess($request, $team);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $duplicate = LogisticsCategory::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])->exists();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => ['Ya existe una categoría con ese nombre.'],
            ]);
        }

        LogisticsCategory::create(['name' => trim($data['name'])]);

        return back()->with('success', 'Categoría creada.');
    }

    private function authorizeTeamAccess(Request $request, string $team): void
    {
        $user = $request->user();
        if ($user->can('equipos.gestionar-todos')) {
            return;
        }
        abort_unless($user->teamSlug() === $team, 403);
    }
}
