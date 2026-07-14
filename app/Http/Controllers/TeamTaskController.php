<?php

namespace App\Http\Controllers;

use App\Models\TeamTask;
use App\Models\Year;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TeamTaskController extends Controller
{
    public function index(Request $request, string $team): Response
    {
        Gate::authorize('tareas.ver');
        $this->authorizeTeamAccess($request, $team);

        $year = $request->filled('year_id')
            ? Year::findOrFail($request->year_id)
            : Year::where('is_active', true)->firstOrFail();

        $tasks = TeamTask::with([
            'completer:id,name',
            'items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
        ])
            ->where('team', $team)
            ->where('year_id', $year->id)
            ->orderBy('is_completed')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return Inertia::render('Teams/Show', [
            'team' => $team,
            'tasks' => $tasks,
            'year' => $year->only('id', 'year', 'label'),
            'canManage' => $request->user()->can('tareas.gestionar-propio-equipo'),
        ]);
    }

    public function store(Request $request, string $team): RedirectResponse
    {
        Gate::authorize('tareas.gestionar-propio-equipo');
        $this->authorizeTeamAccess($request, $team);

        $data = $this->validateTaskData($request);

        $year = $request->filled('year_id')
            ? Year::findOrFail($request->year_id)
            : Year::where('is_active', true)->firstOrFail();

        $maxOrder = TeamTask::where('team', $team)->where('year_id', $year->id)->max('sort_order') ?? 0;

        TeamTask::create([
            'team' => $team,
            'year_id' => $year->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'notes' => $data['notes'] ?? null,
            'optimal_date' => $data['optimal_date'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'sort_order' => $maxOrder + 1,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Tarea creada.');
    }

    public function update(Request $request, string $team, TeamTask $task): RedirectResponse
    {
        Gate::authorize('tareas.gestionar-propio-equipo');
        $this->authorizeTeamAccess($request, $team);
        $this->ensureTaskBelongsToTeam($task, $team);

        $data = $this->validateTaskData($request);
        $task->update($data);

        return back()->with('success', 'Tarea actualizada.');
    }

    public function toggle(Request $request, string $team, TeamTask $task): RedirectResponse
    {
        Gate::authorize('tareas.gestionar-propio-equipo');
        $this->authorizeTeamAccess($request, $team);
        $this->ensureTaskBelongsToTeam($task, $team);

        $completing = ! $task->is_completed;
        $now    = now();
        $userId = $request->user()->id;

        DB::transaction(function () use ($task, $completing, $now, $userId) {
            $task->update([
                'is_completed' => $completing,
                'completed_at' => $completing ? $now : null,
                'completed_by' => $completing ? $userId : null,
            ]);

            if ($completing) {
                // Solo actualizar subtareas que aún no estaban completadas;
                // las ya completadas conservan su completed_at y completed_by.
                $task->items()
                    ->where('is_completed', false)
                    ->update([
                        'is_completed' => true,
                        'completed_at' => $now,
                        'completed_by' => $userId,
                    ]);
            } else {
                // Desmarcar todas las subtareas sin excepción.
                $task->items()->update([
                    'is_completed' => false,
                    'completed_at' => null,
                    'completed_by' => null,
                ]);
            }
        });

        return back();
    }

    public function destroy(Request $request, string $team, TeamTask $task): RedirectResponse
    {
        Gate::authorize('tareas.gestionar-propio-equipo');
        $this->authorizeTeamAccess($request, $team);
        $this->ensureTaskBelongsToTeam($task, $team);

        $task->delete();

        return back()->with('success', 'Tarea eliminada.');
    }

    private function authorizeTeamAccess(Request $request, string $team): void
    {
        $user = $request->user();
        if ($user->can('equipos.gestionar-todos')) {
            return;
        }
        abort_unless($user->teamSlug() === $team, 403);
    }

    private function ensureTaskBelongsToTeam(TeamTask $task, string $team): void
    {
        abort_unless($task->team === $team, 404);
    }

    private function validateTaskData(Request $request): array
    {
        $data = $request->validate([
            'title'        => ['required', 'string', 'max:255'],
            'description'  => ['nullable', 'string'],
            'notes'        => ['nullable', 'string'],
            'optimal_date' => ['nullable', 'date'],
            'due_date'     => ['nullable', 'date'],
        ]);

        if (
            ! empty($data['due_date']) &&
            ! empty($data['optimal_date']) &&
            $data['due_date'] < $data['optimal_date']
        ) {
            throw ValidationException::withMessages([
                'due_date' => ['La fecha límite no puede ser anterior a la fecha óptima.'],
            ]);
        }

        return $data;
    }
}
