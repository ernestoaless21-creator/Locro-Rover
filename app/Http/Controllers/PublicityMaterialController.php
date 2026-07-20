<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePublicityMaterialRequest;
use App\Http\Requests\UpdatePublicityMaterialRequest;
use App\Models\PublicityCategory;
use App\Models\PublicityMaterial;
use App\Models\Year;
use App\Services\PublicityMaterialService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fase 16: publicidad histórica. Sigue exactamente el esquema de permisos de
 * TeamDocumentController (Fase 11): 'tareas.ver' para ver/descargar,
 * 'tareas.gestionar-propio-equipo' para gestionar, y authorizeTeamAccess()
 * para que solo integrantes de Publicidad (o un admin con
 * 'equipos.gestionar-todos') puedan operar sobre este equipo.
 */
class PublicityMaterialController extends Controller
{
    public function __construct(private readonly PublicityMaterialService $materialService) {}

    public function index(Request $request, string $team): Response
    {
        Gate::authorize('tareas.ver');
        $this->authorizeTeamAccess($request, $team);

        $year = $request->filled('year_id')
            ? Year::findOrFail($request->year_id)
            : Year::where('is_active', true)->firstOrFail();

        // Más recientes primero: por fecha del material cuando existe, y por
        // fecha de subida cuando no.
        $materials = PublicityMaterial::with(['category', 'uploader:id,name'])
            ->where('year_id', $year->id)
            ->orderByRaw('COALESCE(material_date, created_at) DESC')
            ->get();

        return Inertia::render('Publicity/Index', [
            'team' => $team,
            'year' => $year->toBasicArray(),
            'materials' => $materials,
            'categories' => PublicityCategory::orderBy('name')->get(),
            'canManage' => $request->user()->can('tareas.gestionar-propio-equipo'),
        ]);
    }

    public function store(StorePublicityMaterialRequest $request, string $team): RedirectResponse
    {
        Gate::authorize('tareas.gestionar-propio-equipo');
        $this->authorizeTeamAccess($request, $team);

        $data = $request->validated();

        $year = $request->filled('year_id')
            ? Year::findOrFail($request->year_id)
            : Year::where('is_active', true)->firstOrFail();
        Gate::authorize('mutate', $year);

        $this->materialService->store(
            file: $request->file('file'),
            yearId: $year->id,
            categoryId: $data['publicity_category_id'],
            title: $data['title'],
            description: $data['description'] ?? null,
            notes: $data['notes'] ?? null,
            materialDate: $data['material_date'] ?? null,
            uploadedBy: $request->user()->id,
        );

        return back()->with('success', 'Material subido.');
    }

    public function update(UpdatePublicityMaterialRequest $request, string $team, PublicityMaterial $material): RedirectResponse
    {
        Gate::authorize('tareas.gestionar-propio-equipo');
        $this->authorizeTeamAccess($request, $team);
        Gate::authorize('mutate', $material->year);

        $data = $request->validated();
        unset($data['file']);

        $this->materialService->update($material, $data, $request->file('file'));

        return back()->with('success', 'Material actualizado.');
    }

    public function destroy(Request $request, string $team, PublicityMaterial $material): RedirectResponse
    {
        Gate::authorize('tareas.gestionar-propio-equipo');
        $this->authorizeTeamAccess($request, $team);
        Gate::authorize('mutate', $material->year);

        $this->materialService->delete($material);

        return back()->with('success', 'Material eliminado.');
    }

    public function download(Request $request, string $team, PublicityMaterial $material): StreamedResponse
    {
        Gate::authorize('tareas.ver');
        $this->authorizeTeamAccess($request, $team);

        return $this->materialService->download($material);
    }

    public function view(Request $request, string $team, PublicityMaterial $material): StreamedResponse
    {
        Gate::authorize('tareas.ver');
        $this->authorizeTeamAccess($request, $team);

        return $this->materialService->view($material);
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
