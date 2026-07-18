<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Models\MeetingDecision;
use App\Models\TeamDocument;
use App\Models\User;
use App\Models\Year;
use App\Services\MeetingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class MeetingController extends Controller
{
    public function __construct(private readonly MeetingService $service) {}

    public function index(Request $request): Response
    {
        Gate::authorize('actas.ver');

        $year = $request->filled('year_id')
            ? Year::findOrFail($request->year_id)
            : Year::where('is_active', true)->firstOrFail();

        $meetings = Meeting::with('creator:id,name')
            ->where('year_id', $year->id)
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (Meeting $m) => [
                'id' => $m->id,
                'title' => $m->title,
                'date' => $m->date->toDateString(),
                'secretary_name' => $m->secretary_name,
                'creator' => $m->creator ? ['name' => $m->creator->name] : null,
            ]);

        $years = Year::orderByDesc('year')->get(['id', 'year', 'label']);

        return Inertia::render('Meetings/Index', [
            'meetings' => $meetings,
            'year' => $year->only('id', 'year', 'label'),
            'years' => $years,
            'canManage' => $request->user()->can('actas.gestionar'),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('actas.gestionar');

        $year = $request->filled('year_id')
            ? Year::findOrFail($request->year_id)
            : Year::where('is_active', true)->firstOrFail();

        $activeUsers = User::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $availableDocuments = TeamDocument::where('year_id', $year->id)
            ->orderBy('team')
            ->orderBy('name')
            ->get(['id', 'name', 'team', 'file_name', 'file_size', 'mime_type']);

        return Inertia::render('Meetings/Create', [
            'year' => $year->only('id', 'year', 'label'),
            'activeUsers' => $activeUsers,
            'availableDocuments' => $availableDocuments,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('actas.gestionar');

        $data = $this->validateMeeting($request);

        $year = $request->filled('year_id')
            ? Year::findOrFail($request->year_id)
            : Year::where('is_active', true)->firstOrFail();
        Gate::authorize('mutate', $year);

        $meeting = $this->service->create($data, $year->id, $request->user()->id);

        $this->service->attachDocuments($meeting, $data['document_ids'] ?? [], $year->id);

        return redirect()->route('meetings.show', $meeting)
            ->with('success', 'Acta creada.');
    }

    public function show(Request $request, Meeting $meeting): Response
    {
        Gate::authorize('actas.ver');

        $meeting->load([
            'creator:id,name',
            'decisions',
            'attendances',
            'documents.uploader:id,name',
            'year',
        ]);

        $availableDocuments = TeamDocument::with('uploader:id,name')
            ->where('year_id', $meeting->year_id)
            ->orderBy('team')
            ->orderBy('name')
            ->get()
            ->makeHidden(['file_path']);

        return Inertia::render('Meetings/Show', [
            'meeting' => $this->formatMeeting($meeting),
            'year' => $meeting->year->only(['id', 'year', 'label', 'is_active']),
            'decisionCategories' => MeetingDecision::CATEGORIES,
            'decisionTeams' => MeetingDecision::TEAMS,
            'availableDocuments' => $availableDocuments,
            'canManage' => $request->user()->can('actas.gestionar'),
        ]);
    }

    public function edit(Request $request, Meeting $meeting): Response
    {
        Gate::authorize('actas.gestionar');

        $meeting->load('attendances', 'year');

        $activeUsers = User::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        // Incluir usuarios inactivos que estén en registros de asistencia
        // para que no desaparezcan del formulario de edición
        $existingUserIds = $meeting->attendances->pluck('user_id')->filter()->all();
        $activeUserIds = $activeUsers->pluck('id')->all();
        $extraIds = array_diff($existingUserIds, $activeUserIds);

        $extraUsers = $extraIds
            ? User::whereIn('id', $extraIds)->orderBy('name')->get(['id', 'name'])
            : collect();

        $allUsers = $activeUsers->merge($extraUsers)->sortBy('name')->values();

        return Inertia::render('Meetings/Edit', [
            'meeting' => [
                'id' => $meeting->id,
                'title' => $meeting->title,
                'date' => $meeting->date->toDateString(),
                'development' => $meeting->development,
                'secretary_id' => $meeting->secretary_id,
                'otros_asistentes' => $meeting->otros_asistentes,
                'attendances' => $meeting->attendances->map(fn (MeetingAttendance $a) => [
                    'user_id' => $a->user_id,
                    'is_present' => $a->is_present,
                ])->values(),
            ],
            'year' => $meeting->year->only(['id', 'year', 'label', 'is_active']),
            'activeUsers' => $allUsers,
        ]);
    }

    public function update(Request $request, Meeting $meeting): RedirectResponse
    {
        Gate::authorize('actas.gestionar');
        Gate::authorize('mutate', $meeting->year);

        $data = $this->validateMeeting($request);
        $this->service->update($meeting, $data);

        return redirect()->route('meetings.show', $meeting)
            ->with('success', 'Acta actualizada.');
    }

    public function destroy(Meeting $meeting): RedirectResponse
    {
        Gate::authorize('actas.gestionar');
        Gate::authorize('mutate', $meeting->year);

        $this->service->delete($meeting);

        return redirect()->route('meetings.index')
            ->with('success', 'Acta eliminada.');
    }

    private function validateMeeting(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'development' => ['nullable', 'string'],
            'secretary_id' => ['nullable', 'integer', 'exists:users,id'],
            'otros_asistentes' => ['nullable', 'string', 'max:2000'],
            'attendances' => ['nullable', 'array'],
            'attendances.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'attendances.*.is_present' => ['required', 'boolean'],
            'document_ids' => ['nullable', 'array'],
            'document_ids.*' => ['integer', 'exists:team_documents,id'],
            'year_id' => ['nullable', 'integer', 'exists:years,id'],
        ]);
    }

    private function formatMeeting(Meeting $meeting): array
    {
        $presentes = $meeting->attendances->where('is_present', true)->pluck('user_name')->sort()->values()->all();
        $ausentes = $meeting->attendances->where('is_present', false)->pluck('user_name')->sort()->values()->all();

        return [
            'id' => $meeting->id,
            'title' => $meeting->title,
            'date' => $meeting->date->toDateString(),
            'development' => $meeting->development,
            'secretary_name' => $meeting->secretary_name,
            'otros_asistentes' => $meeting->otros_asistentes,
            'presentes' => $presentes,
            'ausentes' => $ausentes,
            'creator' => $meeting->creator ? ['name' => $meeting->creator->name] : null,
            'decisions' => $meeting->decisions->map(fn (MeetingDecision $d) => [
                'id' => $d->id,
                'text' => $d->text,
                'category' => $d->category,
                'categoryLabel' => MeetingDecision::CATEGORIES[$d->category] ?? $d->category,
                'team' => $d->team,
                'sort_order' => $d->sort_order,
            ])->values(),
            'documents' => $meeting->documents->map(fn (TeamDocument $doc) => [
                'id' => $doc->id,
                'name' => $doc->name,
                'file_name' => $doc->file_name,
                'file_size' => $doc->file_size,
                'mime_type' => $doc->mime_type,
                'team' => $doc->team,
                'uploader' => $doc->uploader ? ['name' => $doc->uploader->name] : null,
            ])->values(),
        ];
    }
}
