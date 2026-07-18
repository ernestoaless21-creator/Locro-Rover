<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLogisticsRecordRequest;
use App\Http\Requests\UpdateLogisticsRecordRequest;
use App\Models\LogisticsCategory;
use App\Models\LogisticsRecord;
use App\Models\Year;
use App\Services\LogisticsRecordService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fase 17: logística histórica. Sigue exactamente el esquema de permisos de
 * TeamDocumentController (Fase 11) / PublicityMaterialController (Fase 16):
 * 'tareas.ver' para ver/descargar/visualizar, 'tareas.gestionar-propio-equipo'
 * para gestionar, y authorizeTeamAccess() para que solo integrantes de
 * Logística (o un admin con 'equipos.gestionar-todos') puedan operar.
 */
class LogisticsRecordController extends Controller
{
    public function __construct(private readonly LogisticsRecordService $recordService) {}

    public function index(Request $request, string $team): Response
    {
        Gate::authorize('tareas.ver');
        $this->authorizeTeamAccess($request, $team);

        $year = $request->filled('year_id')
            ? Year::findOrFail($request->year_id)
            : Year::where('is_active', true)->firstOrFail();

        // Más recientes primero: por fecha del registro cuando existe, y por
        // fecha de subida cuando no.
        $records = LogisticsRecord::with(['category', 'uploader:id,name'])
            ->where('year_id', $year->id)
            ->orderByRaw('COALESCE(record_date, created_at) DESC')
            ->get();

        return Inertia::render('Logistics/Index', [
            'team' => $team,
            'year' => $year->only('id', 'year', 'label'),
            'records' => $records,
            'categories' => LogisticsCategory::orderBy('name')->get(),
            'canManage' => $request->user()->can('tareas.gestionar-propio-equipo'),
        ]);
    }

    public function store(StoreLogisticsRecordRequest $request, string $team): RedirectResponse
    {
        Gate::authorize('tareas.gestionar-propio-equipo');
        $this->authorizeTeamAccess($request, $team);

        $data = $request->validated();

        $year = $request->filled('year_id')
            ? Year::findOrFail($request->year_id)
            : Year::where('is_active', true)->firstOrFail();
        Gate::authorize('mutate', $year);

        $this->recordService->store(
            file: $request->file('file'),
            yearId: $year->id,
            categoryId: $data['logistics_category_id'],
            title: $data['title'],
            description: $data['description'] ?? null,
            purpose: $data['purpose'] ?? null,
            notes: $data['notes'] ?? null,
            recordDate: $data['record_date'] ?? null,
            uploadedBy: $request->user()->id,
        );

        return back()->with('success', 'Material subido.');
    }

    public function update(UpdateLogisticsRecordRequest $request, string $team, LogisticsRecord $record): RedirectResponse
    {
        Gate::authorize('tareas.gestionar-propio-equipo');
        $this->authorizeTeamAccess($request, $team);
        Gate::authorize('mutate', $record->year);

        $data = $request->validated();
        unset($data['file']);

        $this->recordService->update($record, $data, $request->file('file'));

        return back()->with('success', 'Material actualizado.');
    }

    public function destroy(Request $request, string $team, LogisticsRecord $record): RedirectResponse
    {
        Gate::authorize('tareas.gestionar-propio-equipo');
        $this->authorizeTeamAccess($request, $team);
        Gate::authorize('mutate', $record->year);

        $this->recordService->delete($record);

        return back()->with('success', 'Material eliminado.');
    }

    public function download(Request $request, string $team, LogisticsRecord $record): StreamedResponse
    {
        Gate::authorize('tareas.ver');
        $this->authorizeTeamAccess($request, $team);

        return $this->recordService->download($record);
    }

    public function view(Request $request, string $team, LogisticsRecord $record): StreamedResponse
    {
        Gate::authorize('tareas.ver');
        $this->authorizeTeamAccess($request, $team);

        return $this->recordService->view($record);
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
