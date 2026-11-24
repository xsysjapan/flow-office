<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\Attendance\Events\CalendarBulkOperationApplied;
use App\Domain\Attendance\Events\CalendarBulkOperationReverted;
use App\Models\CalendarBulkOperation;
use App\Models\CalendarBulkOperationTarget;
use Ramsey\Uuid\Uuid;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * calendar_bulk_operation.*イベントからcalendar_bulk_operations /
 * calendar_bulk_operation_targetsを作成・更新する(UC-C013)。
 */
class CalendarBulkOperationProjector extends Projector
{
    /** 対象行のidをuuid5で決める名前空間(変更しない。中間版とHEADで同じ値を使う)。 */
    public const TARGET_ID_NAMESPACE = '31a7798f-6768-41ef-b5ed-c1d036bc1215';

    /**
     * 対象行のid。再生のたびに同じ値になるよう、操作IDと適用イベントの`targets`配列内の位置(0始まり)から決める。
     * 取消イベントの`revertedTargetIds`・`excludedTargetIds`はこのidを記録する。
     */
    public static function targetId(string $operationId, int $position): string
    {
        return Uuid::uuid5(self::TARGET_ID_NAMESPACE, "{$operationId}:{$position}")->toString();
    }

    public function onCalendarBulkOperationApplied(CalendarBulkOperationApplied $event): void
    {
        $id = $event->aggregateRootUuid();
        $appliedAt = $event->createdAt();

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

        foreach ($event->targets as $position => $target) {
            CalendarBulkOperationTarget::query()->create([
                'id' => self::targetId($id, (int) $position),
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
            'reverted_at' => $event->createdAt(),
        ]);
    }
}
