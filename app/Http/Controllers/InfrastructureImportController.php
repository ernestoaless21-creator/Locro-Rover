<?php

namespace App\Http\Controllers;

use App\Models\Year;
use App\Services\InfrastructureImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class InfrastructureImportController extends Controller
{
    public function __construct(private readonly InfrastructureImportService $importService) {}

    public function create(Request $request, string $team): Response
    {
        Gate::authorize('infraestructura.inventario.gestionar');

        $years = Year::orderByDesc('year')->get(['id', 'year', 'label', 'is_active']);

        $targetYear = $request->filled('target_year_id')
            ? Year::findOrFail($request->target_year_id)
            : Year::where('is_active', true)->firstOrFail();

        $sourceData = null;
        if ($request->filled('source_year_id')) {
            $sourceYearId = (int) $request->source_year_id;

            $sourceData = [
                'source_year_id'    => $sourceYearId,
                'items'             => $this->importService->sourceItems($sourceYearId),
                'existing_item_ids' => $this->importService->existingItemIds($targetYear->id),
            ];
        }

        return Inertia::render('Infrastructure/Import', [
            'team'       => $team,
            'years'      => $years,
            'targetYear' => $targetYear->toBasicArray(),
            'sourceData' => $sourceData,
        ]);
    }

    public function store(Request $request, string $team): RedirectResponse
    {
        Gate::authorize('infraestructura.inventario.gestionar');

        $data = $request->validate([
            'source_year_id'      => ['required', 'integer', 'exists:years,id'],
            'target_year_id'      => ['required', 'integer', 'exists:years,id'],
            'selected_item_ids'   => ['nullable', 'array'],
            'selected_item_ids.*' => ['integer'],
        ]);

        abort_if(
            $data['source_year_id'] === $data['target_year_id'],
            422,
            'El año origen y destino no pueden ser el mismo.'
        );

        $result = $this->importService->import(
            sourceYearId:    $data['source_year_id'],
            targetYearId:    $data['target_year_id'],
            createdBy:       $request->user()->id,
            selectedItemIds: $data['selected_item_ids'] ?? null,
        );

        $message = "Importación completada: {$result['imported']} elemento(s) importado(s)";
        $message .= $result['skipped_duplicates'] > 0
            ? ", {$result['skipped_duplicates']} ya existían y se conservaron."
            : '.';

        return redirect()
            ->route('infrastructure.index', ['team' => $team, 'year_id' => $data['target_year_id']])
            ->with('success', $message);
    }
}
