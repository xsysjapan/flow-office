<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * calendar_bulk_operation.reverted (UC-C013 手順5: 一括操作を取消す)。
 * 取消時点で実績・締め済みになった対象は取消対象から除外し、除外件数を結果に含める。
 */
class CalendarBulkOperationReverted extends ShouldBeStored
{
    /**
     * @param  list<string>  $revertedTargetIds  calendar_bulk_operation_targets.idのうち実際に取消した対象
     * @param  list<string>  $excludedTargetIds  実績・締め済みのため取消対象から除外したcalendar_bulk_operation_targets.id
     */
    public function __construct(
        public readonly array $revertedTargetIds,
        public readonly array $excludedTargetIds,
        public readonly string $revertedByUserId,
    ) {}
}
