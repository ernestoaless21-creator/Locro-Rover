<?php

namespace App\Http\Controllers;

use App\Models\TeamTask;
use App\Models\Year;
use App\Services\TeamTaskImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TeamTaskImportController extends Controller
{
    public function __construct(private readonly TeamTaskImportService $importService) {}

    public function create(Request $request): Response
    {
        Gate::authorize('equipos.gestionar-todos');

        $years = Year::orderByDesc('year')->get(['id', 'year', 'label', 'is_active']);

        $targetYear = $request->filled('target_year_id')
            ? Year::findOrFail($request->target_year_id)
            : Year::where('is_active', true)->firstOrFail();

        $teams = TeamTask::TEAMS;

        $preselectedTeam = in_array($request->team, $teams) ? $request->team : null;

        $sourceData = null;
        if ($request->filled('source_year_id')) {
            $sourceYearId = (int) $request->source_year_id;
            $targetYearId = $targetYear->id;

            $sourceCounts   = $this->importService->countsByTeam($sourceYearId, $teams);
            $conflicts      = $this->importService->conflictingTeams($targetYearId, $teams);
            $targetCounts   = $this->importService->countsByTeam($targetYearId, $teams);

            $sourceData = [
                'source_year_id' => $sourceYearId,
                'source_counts'  => $sourceCounts,
                'target_counts'  => $targetCounts,
                'conflicts'      => $conflicts,
            ];
        }

        return Inertia::render('Teams/Import', [
            'years'           => $years,
            'targetYear'      => $targetYear->toBasicArray(),
            'teams'           => $teams,
            'sourceData'      => $sourceData,
            'preselectedTeam' => $preselectedTeam,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('equipos.gestionar-todos');

        $data = $request->validate([
            'source_year_id'    => ['required', 'integer', 'exists:years,id'],
            'target_year_id'    => ['required', 'integer', 'exists:years,id'],
            'teams'             => ['required', 'array', 'min:1'],
            'teams.*'           => ['required', 'string', 'in:' . implode(',', TeamTask::TEAMS)],
            'conflict_resolutions'   => ['nullable', 'array'],
            'conflict_resolutions.*' => ['nullable', 'string', 'in:skip,replace,keep'],
        ]);

        abort_if(
            $data['source_year_id'] === $data['target_year_id'],
            422,
            'El año fuente y el año destino no pueden ser el mismo.'
        );

        $results = $this->importService->import(
            sourceYearId:        $data['source_year_id'],
            targetYearId:        $data['target_year_id'],
            teams:               $data['teams'],
            conflictResolutions: $data['conflict_resolutions'] ?? [],
            createdBy:           $request->user()->id,
        );

        $importedCount = collect($results)->where('imported', true)->count();
        $skippedCount  = collect($results)->where('imported', false)->count();

        $message = "Importación completada: {$importedCount} equipo(s) importado(s)";
        if ($skippedCount > 0) {
            $message .= ", {$skippedCount} conservado(s).";
        } else {
            $message .= '.';
        }

        $firstTeam = $data['teams'][0];

        return redirect()
            ->route('teams.show', $firstTeam)
            ->with('success', $message);
    }
}
