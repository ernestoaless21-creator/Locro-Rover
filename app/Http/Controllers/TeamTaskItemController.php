<?php

namespace App\Http\Controllers;

use App\Models\TeamTask;
use App\Models\TeamTaskItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TeamTaskItemController extends Controller
{
    public function store(Request $request, string $team, TeamTask $task): RedirectResponse
    {
        Gate::authorize('tareas.gestionar-propio-equipo');
        $this->authorizeTeamAccess($request, $team);
        abort_unless($task->team === $team, 404);
        Gate::authorize('mutate', $task->year);

        $data = $request->validate(['title' => ['required', 'string', 'max:255']]);

        $maxOrder = TeamTaskItem::where('team_task_id', $task->id)->max('sort_order') ?? 0;

        TeamTaskItem::create([
            'team_task_id' => $task->id,
            'title' => $data['title'],
            'sort_order' => $maxOrder + 1,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Paso agregado.');
    }

    public function update(Request $request, string $team, TeamTask $task, TeamTaskItem $item): RedirectResponse
    {
        Gate::authorize('tareas.gestionar-propio-equipo');
        $this->authorizeTeamAccess($request, $team);
        abort_unless($task->team === $team, 404);
        abort_unless($item->team_task_id === $task->id, 404);
        Gate::authorize('mutate', $task->year);

        $data = $request->validate(['title' => ['required', 'string', 'max:255']]);
        $item->update(['title' => $data['title']]);

        return back()->with('success', 'Paso actualizado.');
    }

    public function toggle(Request $request, string $team, TeamTask $task, TeamTaskItem $item): RedirectResponse
    {
        Gate::authorize('tareas.gestionar-propio-equipo');
        $this->authorizeTeamAccess($request, $team);
        abort_unless($task->team === $team, 404);
        abort_unless($item->team_task_id === $task->id, 404);
        Gate::authorize('mutate', $task->year);

        $completing = ! $item->is_completed;
        $now = now();
        $userId = $request->user()->id;

        DB::transaction(function () use ($task, $item, $completing, $now, $userId) {
            $item->update([
                'is_completed' => $completing,
                'completed_at' => $completing ? $now : null,
                'completed_by' => $completing ? $userId : null,
            ]);

            if ($completing) {
                // Si todas las subtareas quedan completadas → auto-completar la tarea principal.
                $stillPending = $task->items()->where('is_completed', false)->exists();
                if (! $stillPending && ! $task->is_completed) {
                    $task->update([
                        'is_completed' => true,
                        'completed_at' => $now,
                        'completed_by' => $userId,
                    ]);
                }
            } else {
                // Si la tarea principal estaba completada → volver a pendiente.
                if ($task->is_completed) {
                    $task->update([
                        'is_completed' => false,
                        'completed_at' => null,
                        'completed_by' => null,
                    ]);
                }
            }
        });

        return back();
    }

    public function destroy(Request $request, string $team, TeamTask $task, TeamTaskItem $item): RedirectResponse
    {
        Gate::authorize('tareas.gestionar-propio-equipo');
        $this->authorizeTeamAccess($request, $team);
        abort_unless($task->team === $team, 404);
        abort_unless($item->team_task_id === $task->id, 404);
        Gate::authorize('mutate', $task->year);

        $item->delete();

        return back()->with('success', 'Paso eliminado.');
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
