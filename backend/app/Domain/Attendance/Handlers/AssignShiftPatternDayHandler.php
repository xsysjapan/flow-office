<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\AssignShiftPatternDay;
use App\Domain\Attendance\Events\EmployeeShiftAssigned;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Models\EmployeeShiftAssignment;
use App\Models\ShiftPattern;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;

/**
 * UC-C004 手順3〜4: 3交代制シフト表で、社員の特定日にシフトパターンを割り当てる。
 * 日跨ぎ勤務(`shift_patterns.crosses_midnight`)はplanned_start_at/planned_end_atを
 * datetimeで保持することで日付境界のバグを避ける(docs/08「3交代制など日跨ぎ勤務」参照)。
 *
 * @implements CommandHandler<AssignShiftPatternDay>
 */
class AssignShiftPatternDayHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

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

        // 'work_date' はdateキャストのためDB上はdatetime文字列で保存される。
        // updateOrCreateの厳密一致検索では既存行を見つけられないため、whereDateで明示的に検索する。
        $assignment = EmployeeShiftAssignment::query()
            ->where('user_id', $command->userId)
            ->whereDate('work_date', $workDate->toDateString())
            ->first() ?? new EmployeeShiftAssignment([
                'user_id' => $command->userId,
                'work_date' => $workDate->toDateString(),
            ]);

        $assignment->fill([
            'work_style_id' => $command->workStyleId,
            'shift_pattern_id' => $pattern->id,
            'day_type' => $pattern->code,
            'is_working_day' => $pattern->isWorkingPattern(),
            'is_legal_holiday' => $command->isLegalHoliday,
            'is_company_holiday' => $command->isCompanyHoliday,
            'planned_start_at' => $plannedStartAt,
            'planned_end_at' => $plannedEndAt,
            'planned_break_minutes' => $pattern->break_minutes,
            // 公開(UC-C004手順6)までは下書き扱いにする。
            'is_published' => false,
        ])->save();

        $this->eventStore->append(
            aggregateType: 'employee_shift_assignment',
            aggregateId: (string) $assignment->id,
            event: new EmployeeShiftAssigned(
                employeeShiftAssignmentId: $assignment->id,
                userId: $assignment->user_id,
                workDate: $assignment->work_date->toDateString(),
                workStyleId: $command->workStyleId,
                shiftPatternId: $pattern->id,
                dayType: $assignment->day_type,
                isWorkingDay: $assignment->is_working_day,
                isLegalHoliday: $assignment->is_legal_holiday,
                isCompanyHoliday: $assignment->is_company_holiday,
                plannedStartAt: $plannedStartAt?->toIso8601String(),
                plannedEndAt: $plannedEndAt?->toIso8601String(),
                plannedBreakMinutes: $pattern->break_minutes,
                assignedByUserId: $command->assignedByUserId,
            ),
        );

        return $assignment;
    }
}
