<?php

namespace App\Domain\Attendance\Services;

use App\Models\EmployeeCalendarEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Resolves gaps in employee_calendar_entries from the employee's effective work style and
 * its published company calendar. If no work style is available, a weekday/weekend
 * provisional schedule is returned without persisting it.
 */
class ProvisionalScheduleCalculator
{
    public function __construct(
        private readonly EffectiveScheduleResolver $effectiveScheduleResolver,
    ) {}

    /**
     * @param  Collection<int, EmployeeCalendarEntry>  $existingAssignments
     * @return list<EmployeeCalendarEntry>
     */
    public function fillGaps(string $userId, string $from, string $to, Collection $existingAssignments): array
    {
        $existingDates = $existingAssignments
            ->map(fn (EmployeeCalendarEntry $entry) => $entry->work_date->toDateString())
            ->all();
        $resolved = [];

        foreach (Carbon::parse($from)->toPeriod(Carbon::parse($to)) as $date) {
            $dateString = $date->toDateString();
            if (in_array($dateString, $existingDates, true)) {
                continue;
            }

            $entry = $this->effectiveScheduleResolver->resolve($userId, $date);
            if ($entry !== null) {
                $entry->provisional = false;
                $entry->schedule_source = 'company_calendar';
                $resolved[] = $entry;

                continue;
            }

            $isWorkingDay = $date->dayOfWeekIso < 6;
            $entry = new EmployeeCalendarEntry([
                'user_id' => $userId,
                'work_date' => $dateString,
                'work_style_id' => null,
                'shift_pattern_id' => null,
                'day_type' => $isWorkingDay ? 'weekday' : 'company_holiday',
                'is_working_day' => $isWorkingDay,
                'is_legal_holiday' => $date->dayOfWeekIso === 7,
                'is_company_holiday' => ! $isWorkingDay,
                'schedule_state' => $isWorkingDay ? 'WORK' : 'OFF',
                'planned_start_at' => null,
                'planned_end_at' => null,
                'planned_break_minutes' => 0,
                'is_published' => true,
                'is_manually_overridden' => false,
            ]);
            $entry->provisional = true;
            $entry->schedule_source = 'provisional';
            $resolved[] = $entry;
        }

        return $resolved;
    }
}
