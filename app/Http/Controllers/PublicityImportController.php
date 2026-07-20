<?php

namespace App\Http\Controllers;

use App\Models\Year;
use App\Services\PublicityImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PublicityImportController extends Controller
{
    public function __construct(private readonly PublicityImportService $importService) {}

    public function create(Request $request, string $team): Response
    {
        Gate::authorize('tareas.gestionar-propio-equipo');
        $this->authorizeTeamAccess($request, $team);

        $years = Year::orderByDesc('year')->get(['id', 'year', 'label', 'is_active']);

        $targetYear = $request->filled('target_year_id')
            ? Year::findOrFail($request->target_year_id)
            : Year::where('is_active', true)->firstOrFail();

        $sourceData = null;
        if ($request->filled('source_year_id')) {
            $sourceYearId = (int) $request->source_year_id;

            $sourceData = [
                'source_year_id' => $sourceYearId,
                'materials'      => $this->importService->sourceMaterials($sourceYearId, $targetYear->id),
            ];
        }

        return Inertia::render('Publicity/Import', [
            'team'       => $team,
            'years'      => $years,
            'targetYear' => $targetYear->toBasicArray(),
            'sourceData' => $sourceData,
        ]);
    }

    public function store(Request $request, string $team): RedirectResponse
    {
        Gate::authorize('tareas.gestionar-propio-equipo');
        $this->authorizeTeamAccess($request, $team);

        $data = $request->validate([
            'source_year_id'        => ['required', 'integer', 'exists:years,id'],
            'target_year_id'        => ['required', 'integer', 'exists:years,id'],
            'selected_material_ids'   => ['nullable', 'array'],
            'selected_material_ids.*' => ['integer'],
        ]);

        abort_if(
            $data['source_year_id'] === $data['target_year_id'],
            422,
            'El año origen y destino no pueden ser el mismo.'
        );

        $result = $this->importService->import(
            sourceYearId:          $data['source_year_id'],
            targetYearId:          $data['target_year_id'],
            createdBy:             $request->user()->id,
            selectedMaterialIds:   $data['selected_material_ids'] ?? null,
        );

        $message = "Importación completada: {$result['imported']} material(es) importado(s)";
        $message .= $result['skipped_duplicates'] > 0
            ? ", {$result['skipped_duplicates']} ya existían y se conservaron."
            : '.';

        return redirect()
            ->route('publicity.index', ['team' => $team, 'year_id' => $data['target_year_id']])
            ->with('success', $message);
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
