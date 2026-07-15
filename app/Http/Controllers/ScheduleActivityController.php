<?php

namespace App\Http\Controllers;

use App\Models\ScheduleActivity;
use App\Models\ScheduleDay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ScheduleActivityController extends Controller
{
    public function store(Request $request, ScheduleDay $day): RedirectResponse
    {
        Gate::authorize('cronograma.gestionar');

        $data = $this->validateActivity($request);

        $maxOrder = $day->activities()->max('sort_order') ?? -1;

        ScheduleActivity::create([
            'schedule_day_id' => $day->id,
            'title'           => $data['title'],
            'description'     => $data['description'] ?? null,
            'start_time'      => $data['start_time'] ?? null,
            'end_time'        => $data['end_time'] ?? null,
            'team'            => $data['team'] ?? null,
            'sort_order'      => $maxOrder + 1,
            'created_by'      => $request->user()->id,
        ]);

        return back()->with('success', 'Actividad creada.');
    }

    public function update(Request $request, ScheduleDay $day, ScheduleActivity $activity): RedirectResponse
    {
        Gate::authorize('cronograma.gestionar');
        $this->ensureActivityBelongsToDay($activity, $day);

        $data = $this->validateActivity($request);

        $activity->update([
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'start_time'  => $data['start_time'] ?? null,
            'end_time'    => $data['end_time'] ?? null,
            'team'        => $data['team'] ?? null,
        ]);

        return back()->with('success', 'Actividad actualizada.');
    }

    public function destroy(ScheduleDay $day, ScheduleActivity $activity): RedirectResponse
    {
        Gate::authorize('cronograma.gestionar');
        $this->ensureActivityBelongsToDay($activity, $day);

        $activity->delete();

        return back()->with('success', 'Actividad eliminada.');
    }

    /**
     * Changes an activity's status. Marking it "completed" never invents a
     * real moment on its own — only the explicit "complete_now" flag records
     * the current date/time. Reverting to "pending" clears whatever real
     * moment had been recorded.
     */
    public function updateStatus(Request $request, ScheduleDay $day, ScheduleActivity $activity): RedirectResponse
    {
        Gate::authorize('cronograma.gestionar');
        $this->ensureActivityBelongsToDay($activity, $day);

        $data = $request->validate([
            'status'       => ['required', 'in:pending,completed,skipped'],
            'complete_now' => ['boolean'],
        ]);

        $updateData = ['status' => $data['status']];

        if ($data['status'] === 'pending') {
            $updateData['actual_date'] = null;
            $updateData['actual_time'] = null;
        } elseif ($data['status'] === 'completed' && ($data['complete_now'] ?? false)) {
            $now = now();
            $updateData['actual_date'] = $now->toDateString();
            $updateData['actual_time'] = $now->format('H:i:s');
        }

        $activity->update($updateData);

        return back();
    }

    /**
     * Records or corrects the real execution moment, independently of the
     * status transition. Supports three precision levels: no real moment,
     * date only, or date + time. A time without a date is rejected — we
     * never infer a date to attach to a known time.
     */
    public function updateExecution(Request $request, ScheduleDay $day, ScheduleActivity $activity): RedirectResponse
    {
        Gate::authorize('cronograma.gestionar');
        $this->ensureActivityBelongsToDay($activity, $day);

        $data = $request->validate([
            'actual_date' => ['nullable', 'date'],
            'actual_time' => ['nullable', 'date_format:H:i'],
            'notes'       => ['nullable', 'string'],
        ]);

        if (! empty($data['actual_time']) && empty($data['actual_date'])) {
            throw ValidationException::withMessages([
                'actual_time' => ['La hora real requiere una fecha real.'],
            ]);
        }

        $activity->update([
            'actual_date' => $data['actual_date'] ?? null,
            'actual_time' => $data['actual_time'] ?? null,
            'notes'       => $data['notes'] ?? null,
        ]);

        return back()->with('success', 'Ejecución actualizada.');
    }

    /**
     * Reorders only activities without a start_time — activities with a
     * scheduled start_time are always positioned automatically by that time,
     * so any id for a timed activity in the payload is silently ignored.
     */
    public function reorder(Request $request, ScheduleDay $day): RedirectResponse
    {
        Gate::authorize('cronograma.gestionar');

        $data = $request->validate([
            'ids'   => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $activities = ScheduleActivity::where('schedule_day_id', $day->id)
            ->whereNull('start_time')
            ->get()
            ->keyBy('id');

        foreach ($data['ids'] as $order => $id) {
            if (isset($activities[$id])) {
                $activities[$id]->update(['sort_order' => $order]);
            }
        }

        return back();
    }

    private function ensureActivityBelongsToDay(ScheduleActivity $activity, ScheduleDay $day): void
    {
        abort_unless($activity->schedule_day_id === $day->id, 404);
    }

    private function validateActivity(Request $request): array
    {
        $rules = [
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start_time'  => ['nullable', 'date_format:H:i'],
            'end_time'    => ['nullable', 'date_format:H:i'],
            'team'        => ['nullable', 'string', 'in:' . implode(',', ScheduleActivity::TEAMS)],
        ];

        $data = $request->validate($rules);

        if (! empty($data['end_time']) && empty($data['start_time'])) {
            throw ValidationException::withMessages([
                'end_time' => ['La hora de fin requiere una hora de inicio.'],
            ]);
        }

        if (
            ! empty($data['start_time']) &&
            ! empty($data['end_time']) &&
            $data['end_time'] < $data['start_time']
        ) {
            throw ValidationException::withMessages([
                'end_time' => ['La hora de fin no puede ser anterior a la hora de inicio.'],
            ]);
        }

        return $data;
    }
}
