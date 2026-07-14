<?php

namespace App\Services;

use App\Models\TeamTask;
use App\Models\TeamTaskItem;
use Illuminate\Support\Facades\DB;

/**
 * Servicio de importación de tareas entre ediciones del Locro.
 *
 * Diseñado como base para la Memoria Histórica: en el futuro este patrón
 * puede extenderse para copiar documentación, cronogramas, evaluaciones
 * e inventarios usando la misma lógica de resolución de conflictos.
 */
class TeamTaskImportService
{
    /**
     * Devuelve la cantidad de tareas e ítems por equipo para un año dado.
     * Incluye todos los equipos solicitados, incluso los que tienen 0 tareas.
     */
    public function countsByTeam(int $yearId, array $teams): array
    {
        $tasksByTeam = TeamTask::where('year_id', $yearId)
            ->whereIn('team', $teams)
            ->select('id', 'team')
            ->get()
            ->groupBy('team');

        $result = [];
        foreach ($teams as $team) {
            $teamTasks = $tasksByTeam[$team] ?? collect();
            $taskCount = $teamTasks->count();
            $itemCount = $taskCount > 0
                ? TeamTaskItem::whereIn('team_task_id', $teamTasks->pluck('id'))->count()
                : 0;

            $result[$team] = ['tasks' => $taskCount, 'items' => $itemCount];
        }

        return $result;
    }

    /**
     * Detecta qué equipos ya tienen tareas en el año destino.
     * Devuelve ['equipo' => cantidad_de_tareas_existentes].
     * Los equipos sin conflicto no aparecen en el resultado.
     */
    public function conflictingTeams(int $targetYearId, array $teams): array
    {
        return TeamTask::where('year_id', $targetYearId)
            ->whereIn('team', $teams)
            ->selectRaw('team, count(*) as task_count')
            ->groupBy('team')
            ->pluck('task_count', 'team')
            ->toArray();
    }

    /**
     * Importa tareas y subtareas desde el año fuente al año destino.
     *
     * Campos copiados:  title, description, notes, sort_order (tarea)
     *                   title, sort_order (ítem)
     *
     * Campos NO copiados: is_completed, completed_at, completed_by,
     *                     optimal_date, due_date (las nuevas tareas arrancan limpias)
     *
     * Resolución de conflictos por equipo:
     *   'skip'    → conservar las tareas existentes (no importar este equipo)
     *   'replace' → eliminar las tareas existentes e importar las del año fuente
     *   'keep'    → alias de 'skip'
     *
     * @param int   $sourceYearId
     * @param int   $targetYearId
     * @param array $teams               Equipos a importar
     * @param array $conflictResolutions ['equipo' => 'skip'|'replace'|'keep']
     * @param int   $createdBy           ID del usuario que realiza la importación
     * @return array                     Resultado por equipo con stats
     */
    public function import(
        int $sourceYearId,
        int $targetYearId,
        array $teams,
        array $conflictResolutions,
        int $createdBy
    ): array {
        $results = [];

        DB::transaction(function () use ($sourceYearId, $targetYearId, $teams, $conflictResolutions, $createdBy, &$results) {
            foreach ($teams as $team) {
                $hasExisting = TeamTask::where('year_id', $targetYearId)
                    ->where('team', $team)
                    ->exists();

                if ($hasExisting) {
                    $resolution = $conflictResolutions[$team] ?? 'skip';

                    if ($resolution === 'skip' || $resolution === 'keep') {
                        $results[$team] = [
                            'imported' => false,
                            'tasks'    => 0,
                            'items'    => 0,
                            'reason'   => 'kept',
                        ];
                        continue;
                    }

                    // 'replace': eliminar existentes (cascade borra sus ítems)
                    TeamTask::where('year_id', $targetYearId)
                        ->where('team', $team)
                        ->delete();
                }

                $sourceTasks = TeamTask::with([
                    'items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
                ])
                    ->where('year_id', $sourceYearId)
                    ->where('team', $team)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get();

                $taskCount = 0;
                $itemCount = 0;

                foreach ($sourceTasks as $sourceTask) {
                    $newTask = TeamTask::create([
                        'team'        => $team,
                        'year_id'     => $targetYearId,
                        'title'       => $sourceTask->title,
                        'description' => $sourceTask->description,
                        'notes'       => $sourceTask->notes,
                        'sort_order'  => $sourceTask->sort_order,
                        'created_by'  => $createdBy,
                        // is_completed, completed_at, completed_by: no se copian
                        // optimal_date, due_date: no se copian
                    ]);

                    $taskCount++;

                    foreach ($sourceTask->items as $sourceItem) {
                        TeamTaskItem::create([
                            'team_task_id' => $newTask->id,
                            'title'        => $sourceItem->title,
                            'sort_order'   => $sourceItem->sort_order,
                            'created_by'   => $createdBy,
                            // is_completed, completed_at, completed_by: no se copian
                        ]);
                        $itemCount++;
                    }
                }

                $results[$team] = [
                    'imported' => true,
                    'tasks'    => $taskCount,
                    'items'    => $itemCount,
                ];
            }
        });

        return $results;
    }
}
