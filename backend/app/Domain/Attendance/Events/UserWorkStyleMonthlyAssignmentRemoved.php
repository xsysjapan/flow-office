<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * user_work_style_monthly_assignment.removed (指示書 13章: 「会社のデフォルトを使用」に
 * 戻すため、対象月の個別割当を取り消す)。集約ID(user_work_style_monthly_assignments.id)は
 * `aggregateRootUuid()`から取得する。
 */
class UserWorkStyleMonthlyAssignmentRemoved extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $yearMonth,
        public readonly string $previousWorkStyleId,
        public readonly string $removedByUserId,
    ) {}
}
