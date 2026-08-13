<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\CalendarBulkOperationAggregate;
use App\Domain\Attendance\Aggregates\EmployeeCalendarEntryAggregate;
use App\Domain\Attendance\Commands\RevertCalendarBulkOperation;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\CalendarBulkOperation;
use App\Models\CalendarBulkOperationTarget;

/**
 * UC-C013 手順5: 一括操作を取消す。取消時点で実績・締め済みになった対象は取消対象から除外し、
 * 除外件数を結果に含める(全体を失敗にはしない)。
 *
 * @implements CommandHandler<RevertCalendarBulkOperation>
 */
class RevertCalendarBulkOperationHandler implements CommandHandler
{
    public function __construct(
        private readonly AttendanceEditGuard $guard,
    ) {}

    public function handle(Command $command): CalendarBulkOperation
    {
        assert($command instanceof RevertCalendarBulkOperation);

        $bulkOperation = CalendarBulkOperation::query()->findOrFail($command->calendarBulkOperationId);

        $revertedIds = [];
        $excludedIds = [];

        foreach ($bulkOperation->targets()->where('result', 'applied')->get() as $target) {
            if (! $this->guard->isMutable(null, $target->user_id, $target->work_date->toDateString())) {
                $excludedIds[] = $target->id;

                continue;
            }

            $this->restore($target, $command->revertedByUserId);
            $revertedIds[] = $target->id;
        }

        CalendarBulkOperationAggregate::retrieve($bulkOperation->id)
            ->revert(
                revertedTargetIds: $revertedIds,
                excludedTargetIds: $excludedIds,
                revertedByUserId: $command->revertedByUserId,
            )
            ->persist();

        return CalendarBulkOperation::query()->findOrFail($bulkOperation->id);
    }

    private function restore(CalendarBulkOperationTarget $target, string $revertedByUserId): void
    {
        if ($target->employee_calendar_entry_id === null) {
            return;
        }

        $snapshot = $target->previous_snapshot;

        // previous_snapshotが無い(=一括操作適用前はその日の行が存在しなかった)場合は、
        // 完全な削除に相当する操作が集約に無いため、未割当状態に戻すことで近似する。
        // work_style_idはemployee_calendar_entriesがNOT NULL制約を持つため、現在値を
        // そのまま引き継ぐ(値そのものに意味は無くなるが、行自体は残す)。
        $snapshot ??= [
            'work_style_id' => $target->employeeCalendarEntry?->work_style_id,
            'shift_pattern_id' => null,
            'day_type' => 'weekday',
            'is_working_day' => false,
            'is_legal_holiday' => false,
            'is_company_holiday' => false,
            'planned_start_at' => null,
            'planned_end_at' => null,
            'planned_break_minutes' => 0,
            'planned_break_start_at' => null,
            'planned_break_end_at' => null,
            'is_published' => false,
            'is_manually_overridden' => false,
            'schedule_state' => 'UNASSIGNED',
            'entry_type' => null,
            'source_type' => null,
        ];

        EmployeeCalendarEntryAggregate::retrieve($target->employee_calendar_entry_id)
            ->assign(
                userId: $target->user_id,
                workDate: $target->work_date->toDateString(),
                workStyleId: $snapshot['work_style_id'],
                shiftPatternId: $snapshot['shift_pattern_id'],
                dayType: $snapshot['day_type'],
                isWorkingDay: $snapshot['is_working_day'],
                isLegalHoliday: $snapshot['is_legal_holiday'],
                isCompanyHoliday: $snapshot['is_company_holiday'],
                plannedStartAt: $snapshot['planned_start_at'],
                plannedEndAt: $snapshot['planned_end_at'],
                plannedBreakMinutes: $snapshot['planned_break_minutes'],
                plannedBreakStartAt: $snapshot['planned_break_start_at'],
                plannedBreakEndAt: $snapshot['planned_break_end_at'],
                isPublished: $snapshot['is_published'],
                isManuallyOverridden: $snapshot['is_manually_overridden'],
                assignedByUserId: $revertedByUserId,
                scheduleState: $snapshot['schedule_state'],
                entryType: $snapshot['entry_type'],
                sourceType: $snapshot['source_type'],
                bulkOperationId: $snapshot['bulk_operation_id'] ?? null,
            )
            ->persist();
    }
}
