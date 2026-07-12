<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\EditEmployeeShiftAssignment;
use App\Domain\Attendance\Events\EmployeeShiftPlanChanged;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\EmployeeShiftAssignment;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;

/**
 * 勤務予定(所定労働時間)を編集する。1か月単位変形労働時間制で、特定の日だけあらかじめ
 * 8時間を超える所定労働時間を設定する場合などに使う(docs/08-usecases-calendar-shift.md
 * 「1か月単位変形労働時間制」参照)。
 *
 * 実績が既にある勤務日の予定は変更できない。既に発生した時間外労働を、シフトの事後変更で
 * 通常勤務へ振り替えることを防ぐため(同5.3節「期間途中に管理者が勤務予定を事後変更して、
 * 時間外労働を通常勤務へ振り替えるような処理を許可しない」)。
 *
 * 出勤日(attendance_days)はその月が編集不可になるまで自由に作成・削除できる(UC-A016)ため、
 * 「実績が既にある勤務日か」の判定だけでは、出勤日を一旦削除してから予定を変更し、その後
 * 同じ実績で出勤日を作り直すことで上記の制約を回避できてしまう。これを防ぐため、月次が
 * 承認済み以降(AttendanceEditGuard)の場合も変更を禁止する。
 *
 * @implements CommandHandler<EditEmployeeShiftAssignment>
 */
class EditEmployeeShiftAssignmentHandler implements CommandHandler
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly AttendanceEditGuard $guard,
    ) {}

    public function handle(Command $command): EmployeeShiftAssignment
    {
        assert($command instanceof EditEmployeeShiftAssignment);

        $assignment = EmployeeShiftAssignment::query()->with('workStyle')->findOrFail($command->employeeShiftAssignmentId);

        $hasActualAttendance = AttendanceDay::query()
            ->where('user_id', $assignment->user_id)
            ->whereDate('work_date', $assignment->work_date->toDateString())
            ->whereNotNull('actual_start_at')
            ->exists();

        if ($hasActualAttendance) {
            throw new DomainRuleException('既に勤務実績がある日の勤務予定は変更できません。');
        }

        $this->guard->assertMutable(null, $assignment->user_id, $assignment->work_date->toDateString());

        $plannedStartAt = $command->plannedStartAt !== null ? Carbon::parse($command->plannedStartAt) : null;
        $plannedEndAt = $command->plannedEndAt !== null ? Carbon::parse($command->plannedEndAt) : null;

        if ($assignment->workStyle?->work_time_system === WorkStyle::WORK_TIME_SYSTEM_MONTHLY_VARIABLE) {
            $this->assertWithinVariablePeriodCap($assignment, $plannedStartAt, $plannedEndAt, $command->plannedBreakMinutes);
        }

        $previousPlannedStartAt = $assignment->planned_start_at?->toIso8601String();
        $previousPlannedEndAt = $assignment->planned_end_at?->toIso8601String();
        $previousPlannedBreakMinutes = $assignment->planned_break_minutes;

        $assignment->planned_start_at = $plannedStartAt;
        $assignment->planned_end_at = $plannedEndAt;
        $assignment->planned_break_minutes = $command->plannedBreakMinutes;
        $assignment->save();

        $this->eventStore->append(
            aggregateType: 'employee_shift_assignment',
            aggregateId: (string) $assignment->id,
            event: new EmployeeShiftPlanChanged(
                employeeShiftAssignmentId: $assignment->id,
                previousPlannedStartAt: $previousPlannedStartAt,
                previousPlannedEndAt: $previousPlannedEndAt,
                previousPlannedBreakMinutes: $previousPlannedBreakMinutes,
                plannedStartAt: $plannedStartAt?->toIso8601String(),
                plannedEndAt: $plannedEndAt?->toIso8601String(),
                plannedBreakMinutes: $command->plannedBreakMinutes,
                reason: $command->reason,
                editedByUserId: $command->editedByUserId,
            ),
        );

        return $assignment;
    }

    private function assertWithinVariablePeriodCap(
        EmployeeShiftAssignment $assignment,
        ?Carbon $plannedStartAt,
        ?Carbon $plannedEndAt,
        int $plannedBreakMinutes,
    ): void {
        $workStyle = $assignment->workStyle;
        [$periodStart, $periodEnd] = $workStyle->variablePeriodBoundariesFor($assignment->work_date->copy());

        $otherAssignmentsTotalMinutes = EmployeeShiftAssignment::query()
            ->where('user_id', $assignment->user_id)
            ->where('id', '!=', $assignment->id)
            ->where('is_working_day', true)
            ->whereDate('work_date', '>=', $periodStart->toDateString())
            ->whereDate('work_date', '<=', $periodEnd->toDateString())
            ->get()
            ->sum(fn (EmployeeShiftAssignment $other) => $other->plannedWorkMinutes());

        $thisAssignmentMinutes = ($plannedStartAt !== null && $plannedEndAt !== null)
            ? max(0, $plannedStartAt->diffInMinutes($plannedEndAt) - $plannedBreakMinutes)
            : 0;

        $periodDays = $periodStart->diffInDays($periodEnd) + 1;
        $statutoryCapMinutes = (int) round(2400 * $periodDays / 7); // 労基法32条の2: 1週40時間の平均

        $totalMinutes = $otherAssignmentsTotalMinutes + $thisAssignmentMinutes;

        if ($totalMinutes > $statutoryCapMinutes) {
            throw new DomainRuleException(
                "変形期間({$periodStart->toDateString()}〜{$periodEnd->toDateString()})の所定労働時間合計".
                "が法定労働時間の総枠を超えています(設定合計: {$totalMinutes}分 / 上限: {$statutoryCapMinutes}分)。"
            );
        }
    }
}
