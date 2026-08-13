<?php

namespace App\Domain\Attendance\Services;

use App\Models\EmployeeRotationAssignment;
use App\Models\RotationPattern;
use App\Models\ShiftPattern;
use Illuminate\Support\Carbon;

/**
 * 指示書 8.7節・8.8節・UC-C013(rotation_generate): 社員に割り当てられたローテーション基準から、
 * 対象日1日分のシフトパターン割当・勤務予定を計算する。
 *
 * `GenerateRotationCalendarEntriesHandler`(本線)と`CalendarBulkOperationPlanner`
 * (UC-C013 rotation_generate)の両方から呼ばれる、計算ロジック本体(CLAUDE.md原則9)。
 */
class RotationScheduleResolver
{
    public function assignmentFor(string $userId): ?EmployeeRotationAssignment
    {
        return EmployeeRotationAssignment::query()
            ->where('user_id', $userId)
            ->with('rotationPattern.items.shiftPattern')
            ->first();
    }

    public function shiftPatternFor(EmployeeRotationAssignment $assignment, Carbon $date): ?ShiftPattern
    {
        $pattern = $assignment->rotationPattern;
        $itemsBySequence = $pattern->items->keyBy('sequence');
        $sequenceIndex = $assignment->sequenceIndexFor($date, $pattern->cycle_length);
        $item = $itemsBySequence->get($sequenceIndex);

        return $item?->shiftPattern;
    }

    /**
     * @return array{
     *     day_type: string,
     *     is_working_day: bool,
     *     is_legal_holiday: bool,
     *     is_company_holiday: bool,
     *     planned_start_at: ?Carbon,
     *     planned_end_at: ?Carbon,
     *     planned_break_minutes: int,
     *     planned_break_start_at: ?Carbon,
     *     planned_break_end_at: ?Carbon,
     *     schedule_state: string,
     *     work_style_id: ?string,
     *     shift_pattern_id: string,
     * }
     */
    public function resolve(RotationPattern $pattern, ShiftPattern $shiftPattern, Carbon $date): array
    {
        $isWorkingDay = $shiftPattern->isWorkingPattern();

        $plannedStartAt = $shiftPattern->start_time ? $date->copy()->setTimeFromTimeString($shiftPattern->start_time) : null;
        $plannedEndAt = $shiftPattern->end_time ? $date->copy()->setTimeFromTimeString($shiftPattern->end_time) : null;
        if ($plannedEndAt !== null && $shiftPattern->crosses_midnight) {
            $plannedEndAt = $plannedEndAt->addDay();
        }

        $plannedBreakStartAt = $shiftPattern->break_start_time ? $date->copy()->setTimeFromTimeString($shiftPattern->break_start_time) : null;
        $plannedBreakEndAt = $shiftPattern->break_end_time ? $date->copy()->setTimeFromTimeString($shiftPattern->break_end_time) : null;
        if ($plannedBreakStartAt !== null && $plannedBreakEndAt !== null && $plannedBreakEndAt->lessThanOrEqualTo($plannedBreakStartAt)) {
            $plannedBreakEndAt = $plannedBreakEndAt->addDay();
        }

        return [
            'day_type' => $shiftPattern->code,
            'is_working_day' => $isWorkingDay,
            'is_legal_holiday' => false,
            'is_company_holiday' => ! $isWorkingDay,
            'planned_start_at' => $plannedStartAt,
            'planned_end_at' => $plannedEndAt,
            'planned_break_minutes' => $shiftPattern->break_minutes,
            'planned_break_start_at' => $plannedBreakStartAt,
            'planned_break_end_at' => $plannedBreakEndAt,
            'schedule_state' => $isWorkingDay ? 'WORK' : 'OFF',
            'work_style_id' => $pattern->work_style_id,
            'shift_pattern_id' => $shiftPattern->id,
        ];
    }
}
