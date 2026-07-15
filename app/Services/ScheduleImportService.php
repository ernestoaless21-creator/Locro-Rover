<?php

namespace App\Services;

use App\Models\ScheduleActivity;
use App\Models\ScheduleDay;
use App\Models\Year;
use Illuminate\Support\Facades\DB;

class ScheduleImportService
{
    public function sourceSummary(int $yearId): array
    {
        $days = ScheduleDay::where('year_id', $yearId)->withCount('activities')->get();

        return [
            'days'       => $days->count(),
            'activities' => $days->sum('activities_count'),
        ];
    }

    public function targetHasData(int $yearId): bool
    {
        return ScheduleDay::where('year_id', $yearId)->exists();
    }

    public function sourceDays(int $yearId): array
    {
        return ScheduleDay::with([
            'activities' => fn ($q) => $q->orderedChronologically(),
        ])
            ->where('year_id', $yearId)
            ->orderBy('sort_order')
            ->orderBy('date')
            ->orderBy('id')
            ->get(['id', 'year_id', 'date', 'title', 'sort_order'])
            ->map(fn (ScheduleDay $day) => [
                'id'         => $day->id,
                'date'       => $day->date->toDateString(),
                'title'      => $day->title,
                'activities' => $day->activities->map(fn (ScheduleActivity $a) => [
                    'id'         => $a->id,
                    'title'      => $a->title,
                    'start_time' => $a->start_time,
                    'end_time'   => $a->end_time,
                ])->all(),
            ])
            ->all();
    }

    /**
     * @param  int[]|null  $selectedDayIds  Source day IDs to import. Null = import every day.
     * @param  int[]  $excludedActivityIds  Source activity IDs to skip within the imported days.
     */
    public function import(
        int $sourceYearId,
        int $targetYearId,
        int $createdBy,
        ?array $selectedDayIds = null,
        array $excludedActivityIds = [],
    ): array {
        $result = ['days' => 0, 'activities' => 0];

        $sourceYear = Year::findOrFail($sourceYearId);
        $targetYear = Year::findOrFail($targetYearId);

        DB::transaction(function () use ($sourceYearId, $targetYearId, $sourceYear, $targetYear, $createdBy, $selectedDayIds, $excludedActivityIds, &$result) {
            // Delete any existing schedule for the target year
            ScheduleDay::where('year_id', $targetYearId)->delete();

            $sourceDaysQuery = ScheduleDay::with([
                'activities' => fn ($q) => $q->orderedChronologically(),
            ])
                ->where('year_id', $sourceYearId);

            if ($selectedDayIds !== null) {
                $sourceDaysQuery->whereIn('id', $selectedDayIds);
            }

            $sourceDays = $sourceDaysQuery
                ->orderBy('sort_order')
                ->orderBy('date')
                ->orderBy('id')
                ->get();

            foreach ($sourceDays as $day) {
                $newDay = ScheduleDay::create([
                    'year_id'     => $targetYearId,
                    'date'        => $this->adaptDate($day->date->toDateString(), $sourceYear->year, $targetYear->year),
                    'title'       => $day->title,
                    'description' => $day->description,
                    'sort_order'  => $day->sort_order,
                    'created_by'  => $createdBy,
                ]);

                $result['days']++;

                foreach ($day->activities as $activity) {
                    if (in_array($activity->id, $excludedActivityIds, true)) {
                        continue;
                    }

                    ScheduleActivity::create([
                        'schedule_day_id' => $newDay->id,
                        'title'           => $activity->title,
                        'description'     => $activity->description,
                        'start_time'      => $activity->getRawOriginal('start_time'),
                        'end_time'        => $activity->getRawOriginal('end_time'),
                        'team'            => $activity->team,
                        'sort_order'      => $activity->sort_order,
                        'created_by'      => $createdBy,
                        // NOT copied: status, actual_date, actual_time, notes
                    ]);

                    $result['activities']++;
                }
            }
        });

        return $result;
    }

    private function adaptDate(string $dateStr, int $sourceYear, int $targetYear): string
    {
        [, $month, $day] = explode('-', $dateStr);
        $month = (int) $month;
        $day   = (int) $day;

        $maxDay     = cal_days_in_month(CAL_GREGORIAN, $month, $targetYear);
        $adaptedDay = min($day, $maxDay);

        return sprintf('%04d-%02d-%02d', $targetYear, $month, $adaptedDay);
    }
}
