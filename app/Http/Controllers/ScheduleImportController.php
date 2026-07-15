<?php

namespace App\Http\Controllers;

use App\Models\Year;
use App\Services\ScheduleImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ScheduleImportController extends Controller
{
    public function __construct(private readonly ScheduleImportService $importService) {}

    public function create(Request $request): Response
    {
        Gate::authorize('cronograma.gestionar');

        $years = Year::orderByDesc('year')->get(['id', 'year', 'label', 'is_active']);

        $targetYear = $request->filled('target_year_id')
            ? Year::findOrFail($request->target_year_id)
            : Year::where('is_active', true)->firstOrFail();

        $sourceData = null;
        if ($request->filled('source_year_id')) {
            $sourceYearId = (int) $request->source_year_id;

            $sourceData = [
                'source_year_id' => $sourceYearId,
                'source_summary' => $this->importService->sourceSummary($sourceYearId),
                'target_summary' => $this->importService->sourceSummary($targetYear->id),
                'target_has_data' => $this->importService->targetHasData($targetYear->id),
                'source_days' => $this->importService->sourceDays($sourceYearId),
            ];
        }

        return Inertia::render('Schedule/Import', [
            'years'      => $years,
            'targetYear' => $targetYear->only('id', 'year', 'label'),
            'sourceData' => $sourceData,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('cronograma.gestionar');

        $data = $request->validate([
            'source_year_id'          => ['required', 'integer', 'exists:years,id'],
            'target_year_id'          => ['required', 'integer', 'exists:years,id'],
            'selected_day_ids'        => ['nullable', 'array'],
            'selected_day_ids.*'      => ['integer'],
            'excluded_activity_ids'   => ['nullable', 'array'],
            'excluded_activity_ids.*' => ['integer'],
        ]);

        abort_if(
            $data['source_year_id'] === $data['target_year_id'],
            422,
            'El año origen y destino no pueden ser el mismo.'
        );

        $result = $this->importService->import(
            sourceYearId:         $data['source_year_id'],
            targetYearId:         $data['target_year_id'],
            createdBy:            $request->user()->id,
            selectedDayIds:       $data['selected_day_ids'] ?? null,
            excludedActivityIds:  $data['excluded_activity_ids'] ?? [],
        );

        return redirect()
            ->route('schedule.index', ['year_id' => $data['target_year_id']])
            ->with('success', "Importación completada: {$result['days']} día(s) · {$result['activities']} actividad(es).");
    }
}
