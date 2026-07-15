<?php

namespace App\Http\Controllers;

use App\Models\Year;
use App\Services\PurchasePlanImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PurchasePlanImportController extends Controller
{
    public function __construct(private readonly PurchasePlanImportService $importService) {}

    public function create(Request $request, string $team): Response
    {
        Gate::authorize('compras.planificacion.gestionar');

        $years = Year::orderByDesc('year')->get(['id', 'year', 'label', 'is_active']);

        $targetYear = $request->filled('target_year_id')
            ? Year::findOrFail($request->target_year_id)
            : Year::where('is_active', true)->firstOrFail();

        $sourceData = null;
        if ($request->filled('source_year_id')) {
            $sourceYearId = (int) $request->source_year_id;

            $sourceItems        = $this->importService->sourceItems($sourceYearId);
            $existingProductIds = $this->importService->existingProductIds($targetYear->id);

            $sourceData = [
                'source_year_id'       => $sourceYearId,
                'items'                => $sourceItems,
                'existing_product_ids' => $existingProductIds,
            ];
        }

        return Inertia::render('Purchases/Import', [
            'team'       => $team,
            'years'      => $years,
            'targetYear' => $targetYear->only('id', 'year', 'label'),
            'sourceData' => $sourceData,
        ]);
    }

    public function store(Request $request, string $team): RedirectResponse
    {
        Gate::authorize('compras.planificacion.gestionar');

        $data = $request->validate([
            'source_year_id'     => ['required', 'integer', 'exists:years,id'],
            'target_year_id'     => ['required', 'integer', 'exists:years,id'],
            'selected_product_ids'   => ['nullable', 'array'],
            'selected_product_ids.*' => ['integer'],
        ]);

        abort_if(
            $data['source_year_id'] === $data['target_year_id'],
            422,
            'El año origen y destino no pueden ser el mismo.'
        );

        $result = $this->importService->import(
            sourceYearId:        $data['source_year_id'],
            targetYearId:        $data['target_year_id'],
            createdBy:           $request->user()->id,
            selectedProductIds:  $data['selected_product_ids'] ?? null,
        );

        $message = "Importación completada: {$result['imported']} producto(s) importado(s)";
        if ($result['skipped_duplicates'] > 0) {
            $message .= ", {$result['skipped_duplicates']} ya existían y se conservaron.";
        } else {
            $message .= '.';
        }

        return redirect()
            ->route('purchases.index', ['team' => $team, 'year_id' => $data['target_year_id']])
            ->with('success', $message);
    }
}
