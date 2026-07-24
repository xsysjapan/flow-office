<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\EmployeeShiftAssignmentAggregate;
use App\Domain\Attendance\Commands\AssignShiftPatternDay;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\EmployeeShiftAssignment;
use App\Models\ShiftPattern;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * UC-C004 手順3〜4: 3交代制シフト表で、社員の特定日にシフトパターンを割り当てる。
 * 日跨ぎ勤務(`shift_patterns.crosses_midnight`)はplanned_start_at/planned_end_atを
 * datetimeで保持することで日付境界のバグを避ける(docs/08「3交代制など日跨ぎ勤務」参照)。
 *
 * @implements CommandHandler<AssignShiftPatternDay>
 */
class AssignShiftPatternDayHandler implements CommandHandler
{
    public function handle(Command $command): EmployeeShiftAssignment
    {
        assert($command instanceof AssignShiftPatternDay);

        WorkStyle::query()->findOrFail($command->workStyleId);
        $pattern = ShiftPattern::query()->findOrFail($command->shiftPatternId);
        $workDate = Carbon::parse($command->workDate);

        $plannedStartAt = $pattern->start_time ? $workDate->copy()->setTimeFromTimeString($pattern->start_time) : null;
        $plannedEndAt = $pattern->end_time ? $workDate->copy()->setTimeFromTimeString($pattern->end_time) : null;
        if ($plannedEndAt !== null && $pattern->crosses_midnight) {
            $plannedEndAt = $plannedEndAt->addDay();
        }

        $plannedBreakStartAt = $pattern->break_start_time ? $workDate->copy()->setTimeFromTimeString($pattern->break_start_time) : null;
        $plannedBreakEndAt = $pattern->break_end_time ? $workDate->copy()->setTimeFromTimeString($pattern->break_end_time) : null;
        if ($plannedBreakStartAt !== null && $plannedBreakEndAt !== null && $plannedBreakEndAt->lessThanOrEqualTo($plannedBreakStartAt)) {
            // 深夜勤の休憩が日付境界(24時)を跨ぐ場合。
            $plannedBreakEndAt = $plannedBreakEndAt->addDay();
        }

        // 'work_date' はdateキャストのためDB上はdatetime文字列で保存される。
        // 厳密一致検索では既存行を見つけられないため、whereDateで明示的に検索する。
        $existing = EmployeeShiftAssignment::query()
            ->where('user_id', $command->userId)
            ->whereDate('work_date', $workDate->toDateString())
            ->first();

        $id = $existing?->id ?? (string) Str::uuid();

        EmployeeShiftAssignmentAggregate::retrieve($id)
            ->assign(
                userId: $command->userId,
                workDate: $workDate->toDateString(),
                workStyleId: $command->workStyleId,
                shiftPatternId: $pattern->id,
                dayType: $pattern->code,
                isWorkingDay: $pattern->isWorkingPattern(),
                isLegalHoliday: $command->isLegalHoliday,
                isCompanyHoliday: $command->isCompanyHoliday,
                plannedStartAt: $plannedStartAt?->toIso8601String(),
                plannedEndAt: $plannedEndAt?->toIso8601String(),
                plannedBreakMinutes: $pattern->break_minutes,
                plannedBreakStartAt: $plannedBreakStartAt?->toIso8601String(),
                plannedBreakEndAt: $plannedBreakEndAt?->toIso8601String(),
                // 公開(UC-C004手順6)までは下書き扱いにする。
                isPublished: false,
                // 個別上書きとして扱い、ローテーションの再生成(指示書8.8節「未編集日のみ再生成」)で
                // 自動上書きされないようにする。
                isManuallyOverridden: true,
                assignedByUserId: $command->assignedByUserId,
            )
            ->persist();

        return EmployeeShiftAssignment::query()->findOrFail($id);
    }
}
