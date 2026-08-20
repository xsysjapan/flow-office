<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Models\AttendanceDay;
use App\Models\AttendanceWeeklyOvertimeAllocation;
use Illuminate\Support\Carbon;

final class WeeklyOvertimeAutoAllocator
{
    public function __construct(private readonly WeeklyOvertimeCalculator $calculator) {}

    public function allocateNonPrescribed(string $userId, Carbon $workDate): void
    {
        [$weekStart, $weekEnd] = $this->calculator->weekPeriodForDate($userId, $workDate);
        $week = $this->calculator->calculateWeek($userId, $weekStart, $weekEnd);
        $remaining = $week['unallocated_weekly_statutory_excess_overtime_minutes'];
        if ($remaining <= 0) {
            return;
        }

        $days = AttendanceDay::query()
            ->where('user_id', $userId)
            ->whereBetween('work_date', [$weekStart, $weekEnd])
            ->with('calculation')
            ->orderByDesc('work_date')
            ->get();
        $allocations = AttendanceWeeklyOvertimeAllocation::query()
            ->whereIn('attendance_day_id', $days->pluck('id'))
            ->get()
            ->keyBy('attendance_day_id');

        foreach ($days as $day) {
            if ($remaining <= 0 || $day->calculation === null) {
                continue;
            }
            $previous = $allocations->get($day->id);
            $capacity = (int) $day->calculation->non_prescribed_statutory_within_work_minutes;
            if ($capacity <= 0) {
                continue;
            }
            $minutes = min($remaining, $capacity);
            $lateNightCapacity = (int) $day->calculation->late_night_non_prescribed_statutory_within_work_minutes;
            $daytimeCapacity = max(0, $capacity - $lateNightCapacity);
            $lateNightMinutes = max(0, $minutes - $daytimeCapacity);

            AttendanceDayAggregate::retrieve($day->id)->allocateWeeklyOvertime(
                $weekStart,
                (int) ($previous?->prescribed_minutes ?? 0),
                (int) ($previous?->non_prescribed_minutes ?? 0) + $minutes,
                (int) ($previous?->late_night_prescribed_minutes ?? 0),
                (int) ($previous?->late_night_non_prescribed_minutes ?? 0) + $lateNightMinutes,
                $userId,
            )->persist();
            $remaining -= $minutes;
        }
    }
}
