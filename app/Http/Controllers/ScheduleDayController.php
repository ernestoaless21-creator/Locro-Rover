<?php

namespace App\Http\Controllers;

use App\Models\ScheduleActivity;
use App\Models\ScheduleDay;
use App\Models\Year;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ScheduleDayController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('cronograma.ver');

        $year = $request->filled('year_id')
            ? Year::findOrFail($request->year_id)
            : Year::where('is_active', true)->firstOrFail();

        $days = ScheduleDay::with([
            'activities' => fn ($q) => $q->orderedChronologically(),
        ])
            ->where('year_id', $year->id)
            ->orderBy('sort_order')
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        return Inertia::render('Schedule/Index', [
            'year' => $year->toBasicArray(),
            'days' => $days,
            'scheduleNotes' => $year->schedule_notes,
            'canManage' => $request->user()->can('cronograma.gestionar'),
            'teams' => ScheduleActivity::TEAMS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('cronograma.gestionar');

        $data = $this->validateDay($request);

        $year = $request->filled('year_id')
            ? Year::findOrFail($request->year_id)
            : Year::where('is_active', true)->firstOrFail();
        Gate::authorize('mutate', $year);

        $maxOrder = ScheduleDay::where('year_id', $year->id)->max('sort_order') ?? -1;

        ScheduleDay::create([
            'year_id' => $year->id,
            'date' => $data['date'],
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'sort_order' => $maxOrder + 1,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Día creado.');
    }

    public function update(Request $request, ScheduleDay $day): RedirectResponse
    {
        Gate::authorize('cronograma.gestionar');
        Gate::authorize('mutate', $day->year);

        $data = $this->validateDay($request);
        $day->update($data);

        return back()->with('success', 'Día actualizado.');
    }

    public function destroy(ScheduleDay $day): RedirectResponse
    {
        Gate::authorize('cronograma.gestionar');
        Gate::authorize('mutate', $day->year);

        $day->delete();

        return back()->with('success', 'Día eliminado.');
    }

    public function reorder(Request $request): RedirectResponse
    {
        Gate::authorize('cronograma.gestionar');

        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:schedule_days,id'],
        ]);

        // Fase 19: cierra un hueco preexistente -- reorder() no validaba a que
        // edicion pertenecian los IDs recibidos. Se resuelven los años
        // involucrados y se autoriza cada uno antes de tocar nada.
        ScheduleDay::whereIn('id', $data['ids'])
            ->with('year')
            ->get()
            ->pluck('year')
            ->unique('id')
            ->each(fn (Year $year) => Gate::authorize('mutate', $year));

        foreach ($data['ids'] as $order => $id) {
            ScheduleDay::where('id', $id)->update(['sort_order' => $order]);
        }

        return back();
    }

    public function updateNotes(Request $request): RedirectResponse
    {
        Gate::authorize('cronograma.gestionar');

        $data = $request->validate([
            'notes' => ['nullable', 'string'],
            'year_id' => ['nullable', 'integer', 'exists:years,id'],
        ]);

        $year = $request->filled('year_id')
            ? Year::findOrFail($request->year_id)
            : Year::where('is_active', true)->firstOrFail();
        Gate::authorize('mutate', $year);

        $year->update(['schedule_notes' => $data['notes'] ?? null]);

        return back()->with('success', 'Notas actualizadas.');
    }

    private function validateDay(Request $request): array
    {
        return $request->validate([
            'date' => ['required', 'date'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);
    }
}
