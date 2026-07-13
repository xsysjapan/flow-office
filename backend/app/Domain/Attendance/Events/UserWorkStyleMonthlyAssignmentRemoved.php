<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * user_work_style_monthly_assignment.removed (指示書 13章: 「会社のデフォルトを使用」に
 * 戻すため、対象月の個別割当を取り消す)。
 */
class UserWorkStyleMonthlyAssignmentRemoved implements DomainEvent
{
    public function __construct(
        public readonly int $assignmentId,
        public readonly int $userId,
        public readonly string $yearMonth,
        public readonly int $previousWorkStyleId,
        public readonly int $removedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'user_work_style_monthly_assignment.removed';
    }

    public function payload(): array
    {
        return [
            'assignment_id' => $this->assignmentId,
            'user_id' => $this->userId,
            'year_month' => $this->yearMonth,
            'previous_work_style_id' => $this->previousWorkStyleId,
            'removed_by_user_id' => $this->removedByUserId,
        ];
    }
}
