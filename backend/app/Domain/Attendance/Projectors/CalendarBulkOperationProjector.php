<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\Attendance\Events\CalendarBulkOperationApplied;
use App\Domain\Attendance\Events\CalendarBulkOperationReverted;
use App\Models\CalendarBulkOperation;
use App\Models\CalendarBulkOperationTarget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * calendar_bulk_operation.*イベントからcalendar_bulk_operations /
 * calendar_bulk_operation_targetsを作成・更新する(UC-C013)。
 */
class CalendarBulkOperationProjector extends Projector
{
    public function onCalendarBulkOperationApplied(CalendarBulkOperationApplied $event): void
    {
        $id = $event->aggregateRootUuid();
        $appliedAt = Carbon::now();

        CalendarBulkOperation::query()->updateOrCreate(
            ['id' => $id],
            [
                'operation_type' => $event->operationType,
                'target_scope' => $event->targetScope,
                'conflict_policy' => $event->conflictPolicy,
                'status' => 'applied',
                'requested_by_user_id' => $event->requestedByUserId,
                'applied_at' => $appliedAt,
                'reason' => $event->reason,
            ],
        );

        foreach ($event->targets as $target) {
            CalendarBulkOperationTarget::query()->create([
                'id' => (string) Str::uuid(),
                'calendar_bulk_operation_id' => $id,
                'user_id' => $target['user_id'],
                'work_date' => $target['work_date'],
                'employee_calendar_entry_id' => $target['employee_calendar_entry_id'],
                'result' => $target['result'],
                'error_code' => $target['error_code'],
                'previous_snapshot' => $target['previous_snapshot'],
            ]);
        }
    }

    public function onCalendarBulkOperationReverted(CalendarBulkOperationReverted $event): void
    {
        CalendarBulkOperation::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => 'reverted',
            'reverted_at' => Carbon::now(),
        ]);
    }
}
