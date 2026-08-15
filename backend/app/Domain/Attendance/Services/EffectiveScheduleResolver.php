<?php

namespace App\Domain\Attendance\Services;

use App\Models\EmployeeCalendarEntry;
use Illuminate\Support\Carbon;

/** Resolves an employee's effective schedule without exposing its source to consumers. */
class EffectiveScheduleResolver
{
    public function __construct(
        private readonly WorkStyleFallbackResolver $workStyleFallbackResolver,
        private readonly CalendarDayScheduleResolver $calendarDayScheduleResolver,
    ) {}

    public function resolve(string $userId, Carbon $date, ?EmployeeCalendarEntry $knownEntry = null): ?EmployeeCalendarEntry
    {
        $entry = $knownEntry ?? EmployeeCalendarEntry::query()
            ->with('workStyle.calendar')
            ->where('user_id', $userId)
            ->whereDate('work_date', $date->toDateString())
            ->first();

        if ($entry !== null) {
            return $entry;
        }

        $workStyle = $this->workStyleFallbackResolver->resolveForUser($userId, $date);
        if ($workStyle === null) {
            return null;
        }

        $calendarDay = $this->calendarDayScheduleResolver->calendarDaysForRange(
            $workStyle->company_calendar_id,
            $date->toDateString(),
            $date->toDateString(),
            onlyPublished: true,
        )->get($date->toDateString());

        $schedule = $this->calendarDayScheduleResolver->resolve($workStyle, $date, $calendarDay);
        if ($workStyle->company_calendar_id === null && $date->isWeekend()) {
            $schedule = [
                ...$schedule,
                'day_type' => 'company_holiday',
                'is_working_day' => false,
                'is_legal_holiday' => $date->dayOfWeekIso === 7,
                'is_company_holiday' => $date->dayOfWeekIso !== 7,
                'planned_start_at' => null,
                'planned_end_at' => null,
                'planned_break_minutes' => 0,
                'planned_break_start_at' => null,
                'planned_break_end_at' => null,
                'schedule_state' => 'OFF',
            ];
        }

        $entry = new EmployeeCalendarEntry([
            ...$schedule,
            'user_id' => $userId,
            'work_date' => $date->toDateString(),
            'work_style_id' => $workStyle->id,
            'is_published' => true,
            'is_manually_overridden' => false,
        ]);
        $entry->setRelation('workStyle', $workStyle);
        $entry->schedule_source = $calendarDay !== null ? 'company_calendar' : 'system_default';
        $entry->is_public_holiday = (bool) ($calendarDay?->is_public_holiday ?? false);
        $entry->public_holiday_name = $calendarDay?->public_holiday_name;

        return $entry;
    }
}
