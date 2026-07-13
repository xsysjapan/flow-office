<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\GenerateEmployeeShiftAssignments;
use App\Domain\Attendance\Events\EmployeeShiftAssigned;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Models\EmployeeShiftAssignment;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * UC-C003: 働き方ごとのカレンダーをもとに、指定期間分の勤務予定を一括生成する。
 *
 * @implements CommandHandler<GenerateEmployeeShiftAssignments>
 */
class GenerateEmployeeShiftAssignmentsHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): Collection
    {
        assert($command instanceof GenerateEmployeeShiftAssignments);

        $workStyle = WorkStyle::query()->with('calendar.days')->findOrFail($command->workStyleId);
        $calendarDaysByDate = $workStyle->calendar?->days->keyBy(fn ($day) => $day->date->toDateString()) ?? collect();

        $period = Carbon::parse($command->from)->toPeriod(Carbon::parse($command->to));
        $assignments = collect();

        foreach ($period as $date) {
            $calendarDay = $calendarDaysByDate->get($date->toDateString());
            $isWorkingDay = $calendarDay?->is_working_day ?? true;

            // 'work_date' はdateキャストのためDB上はdatetime文字列で保存される。
            // updateOrCreateの厳密一致検索では既存行を見つけられないため、whereDateで明示的に検索する。
            $assignment = EmployeeShiftAssignment::query()
                ->where('user_id', $command->userId)
                ->whereDate('work_date', $date->toDateString())
                ->first() ?? new EmployeeShiftAssignment([
                    'user_id' => $command->userId,
                    'work_date' => $date->toDateString(),
                ]);

            $plannedStartAt = $isWorkingDay && $workStyle->default_start_time
                ? $date->copy()->setTimeFromTimeString($workStyle->default_start_time) : null;
            $plannedEndAt = $isWorkingDay && $workStyle->default_end_time
                ? $date->copy()->setTimeFromTimeString($workStyle->default_end_time) : null;
            $plannedBreakMinutes = $isWorkingDay ? $workStyle->default_break_minutes : 0;

            $assignment->fill([
                'work_style_id' => $workStyle->id,
                'shift_pattern_id' => null,
                'day_type' => $calendarDay?->day_type ?? 'weekday',
                'is_working_day' => $isWorkingDay,
                'is_legal_holiday' => $calendarDay?->is_legal_holiday ?? false,
                'is_company_holiday' => $calendarDay?->is_company_holiday ?? false,
                'planned_start_at' => $plannedStartAt,
                'planned_end_at' => $plannedEndAt,
                'planned_break_minutes' => $plannedBreakMinutes,
                // カレンダー基準の一括生成は下書き公開の概念を持たず、従来通り即時有効にする。
                'is_published' => true,
            ])->save();

            $this->eventStore->append(
                aggregateType: 'employee_shift_assignment',
                aggregateId: (string) $assignment->id,
                event: new EmployeeShiftAssigned(
                    employeeShiftAssignmentId: $assignment->id,
                    userId: $assignment->user_id,
                    workDate: $assignment->work_date->toDateString(),
                    workStyleId: $workStyle->id,
                    shiftPatternId: null,
                    dayType: $assignment->day_type,
                    isWorkingDay: $assignment->is_working_day,
                    isLegalHoliday: $assignment->is_legal_holiday,
                    isCompanyHoliday: $assignment->is_company_holiday,
                    plannedStartAt: $plannedStartAt?->toIso8601String(),
                    plannedEndAt: $plannedEndAt?->toIso8601String(),
                    plannedBreakMinutes: $plannedBreakMinutes,
                    assignedByUserId: $command->generatedByUserId,
                ),
            );

            $assignments->push($assignment);
        }

        return $assignments;
    }
}
