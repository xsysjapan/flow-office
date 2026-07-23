<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * user_work_style_monthly_assignment.assigned
 * 指定した月にユーザーが属する働き方(work_style)を割り当てる、または変更する。
 * 集約ID(user_work_style_monthly_assignments.id)は`aggregateRootUuid()`から取得する。
 */
class UserWorkStyleAssignedForMonth extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $yearMonth,
        public readonly string $workStyleId,
        public readonly string $assignedByUserId,
    ) {}
}
