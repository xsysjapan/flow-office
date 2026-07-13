<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\GenerateRotationShiftAssignments;
use App\Domain\Attendance\Events\EmployeeShiftAssigned;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\EmployeeRotationAssignment;
use App\Models\EmployeeShiftAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 指示書 8.7節・8.8節: 社員に割り当てられたローテーション基準から、指定期間分の勤務予定を
 * 一括生成する。生成は冪等ではあるが、次の日は自動上書きしない(安全な既定値)。
 *
 * - 既に勤務実績(打刻・実績入力)がある日、または月次が承認済み以降でロックされている日
 * - `overwrite_mode=skip_edited`(既定)の場合、個別に上書き済み(`is_manually_overridden`)の日
 *
 * `overwrite_mode=overwrite_all`は個別上書き済みの日だけを再生成し直す用途で、実績のある日・
 * ロックされた日は安全のためこのモードでも常にスキップする。
 *
 * @implements CommandHandler<GenerateRotationShiftAssignments>
 */
class GenerateRotationShiftAssignmentsHandler implements CommandHandler
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly AttendanceEditGuard $guard,
    ) {}

    /**
     * @return array{generated: Collection<int, EmployeeShiftAssignment>, skipped_dates: list<string>}
     */
    public function handle(Command $command): array
    {
        assert($command instanceof GenerateRotationShiftAssignments);

        $rotationAssignment = EmployeeRotationAssignment::query()
            ->where('user_id', $command->userId)
            ->with('rotationPattern.items.shiftPattern')
            ->first();

        if ($rotationAssignment === null) {
            throw new DomainRuleException('この社員にはローテーションが割り当てられていません。');
        }

        $pattern = $rotationAssignment->rotationPattern;
        $itemsBySequence = $pattern->items->keyBy('sequence');

        $period = Carbon::parse($command->from)->toPeriod(Carbon::parse($command->to));
        $generated = collect();
        $skipped = [];

        foreach ($period as $date) {
            $hasActualAttendance = AttendanceDay::query()
                ->where('user_id', $command->userId)
                ->whereDate('work_date', $date->toDateString())
                ->whereNotNull('actual_start_at')
                ->exists();
            $isLocked = ! $this->guard->isMutable(null, $command->userId, $date->toDateString());

            if ($hasActualAttendance || $isLocked) {
                $skipped[] = $date->toDateString();

                continue;
            }

            $existing = EmployeeShiftAssignment::query()
                ->where('user_id', $command->userId)
                ->whereDate('work_date', $date->toDateString())
                ->first();

            if ($existing?->is_manually_overridden && $command->overwriteMode === GenerateRotationShiftAssignments::OVERWRITE_MODE_SKIP_EDITED) {
                $skipped[] = $date->toDateString();

                continue;
            }

            $sequenceIndex = $rotationAssignment->sequenceIndexFor($date, $pattern->cycle_length);
            $item = $itemsBySequence->get($sequenceIndex);

            if ($item === null) {
                continue;
            }

            $shiftPattern = $item->shiftPattern;

            $plannedStartAt = $shiftPattern->start_time ? $date->copy()->setTimeFromTimeString($shiftPattern->start_time) : null;
            $plannedEndAt = $shiftPattern->end_time ? $date->copy()->setTimeFromTimeString($shiftPattern->end_time) : null;
            if ($plannedEndAt !== null && $shiftPattern->crosses_midnight) {
                $plannedEndAt = $plannedEndAt->addDay();
            }

            $assignment = $existing ?? new EmployeeShiftAssignment([
                'user_id' => $command->userId,
                'work_date' => $date->toDateString(),
            ]);

            $assignment->fill([
                'work_style_id' => $pattern->work_style_id,
                'shift_pattern_id' => $shiftPattern->id,
                'day_type' => $shiftPattern->code,
                'is_working_day' => $shiftPattern->isWorkingPattern(),
                'is_legal_holiday' => false,
                'is_company_holiday' => ! $shiftPattern->isWorkingPattern(),
                'planned_start_at' => $plannedStartAt,
                'planned_end_at' => $plannedEndAt,
                'planned_break_minutes' => $shiftPattern->break_minutes,
                'is_published' => false,
                'is_manually_overridden' => false,
            ])->save();

            $this->eventStore->append(
                aggregateType: 'employee_shift_assignment',
                aggregateId: (string) $assignment->id,
                event: new EmployeeShiftAssigned(
                    employeeShiftAssignmentId: $assignment->id,
                    userId: $assignment->user_id,
                    workDate: $assignment->work_date->toDateString(),
                    workStyleId: $pattern->work_style_id,
                    shiftPatternId: $shiftPattern->id,
                    dayType: $assignment->day_type,
                    isWorkingDay: $assignment->is_working_day,
                    isLegalHoliday: $assignment->is_legal_holiday,
                    isCompanyHoliday: $assignment->is_company_holiday,
                    plannedStartAt: $plannedStartAt?->toIso8601String(),
                    plannedEndAt: $plannedEndAt?->toIso8601String(),
                    plannedBreakMinutes: $shiftPattern->break_minutes,
                    assignedByUserId: $command->generatedByUserId,
                ),
            );

            $generated->push($assignment);
        }

        return ['generated' => $generated, 'skipped_dates' => $skipped];
    }
}
