<?php

namespace App\Http\Controllers;

use App\Models\Year;
use App\Services\LogisticsImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class LogisticsImportController extends Controller
{
    public function __construct(private readonly LogisticsImportService $importService) {}

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
                'records'        => $this->importService->sourceRecords($sourceYearId, $targetYear->id),
            ];
        }

        return Inertia::render('Logistics/Import', [
            'team'       => $team,
            'years'      => $years,
            'targetYear' => $targetYear->only('id', 'year', 'label'),
            'sourceData' => $sourceData,
        ]);
    }

    public function store(Request $request, string $team): RedirectResponse
    {
        Gate::authorize('tareas.gestionar-propio-equipo');
        $this->authorizeTeamAccess($request, $team);

        $data = $request->validate([
            'source_year_id'      => ['required', 'integer', 'exists:years,id'],
            'target_year_id'      => ['required', 'integer', 'exists:years,id'],
            'selected_record_ids'   => ['nullable', 'array'],
            'selected_record_ids.*' => ['integer'],
        ]);

        abort_if(
            $data['source_year_id'] === $data['target_year_id'],
            422,
            'El año origen y destino no pueden ser el mismo.'
        );

        $result = $this->importService->import(
            sourceYearId:       $data['source_year_id'],
            targetYearId:       $data['target_year_id'],
            createdBy:          $request->user()->id,
            selectedRecordIds:  $data['selected_record_ids'] ?? null,
        );

        $message = "Importación completada: {$result['imported']} material(es) importado(s)";
        $message .= $result['skipped_duplicates'] > 0
            ? ", {$result['skipped_duplicates']} ya existían y se conservaron."
            : '.';

        return redirect()
            ->route('logistics.index', ['team' => $team, 'year_id' => $data['target_year_id']])
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
