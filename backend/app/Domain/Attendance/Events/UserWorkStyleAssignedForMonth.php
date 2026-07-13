<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * user_work_style_monthly_assignment.assigned
 * 指定した月にユーザーが属する働き方(work_style)を割り当てる、または変更する。
 */
class UserWorkStyleAssignedForMonth implements DomainEvent
{
    public function __construct(
        public readonly int $userWorkStyleMonthlyAssignmentId,
        public readonly int $userId,
        public readonly string $yearMonth,
        public readonly int $workStyleId,
        public readonly int $assignedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'user_work_style_monthly_assignment.assigned';
    }

    public function payload(): array
    {
        return [
            'user_work_style_monthly_assignment_id' => $this->userWorkStyleMonthlyAssignmentId,
            'user_id' => $this->userId,
            'year_month' => $this->yearMonth,
            'work_style_id' => $this->workStyleId,
            'assigned_by_user_id' => $this->assignedByUserId,
        ];
    }
}
