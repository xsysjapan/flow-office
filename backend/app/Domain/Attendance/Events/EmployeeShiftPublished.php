<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * employee_shift.published (UC-C004 手順6: シフトを公開する)。
 * 下書き状態(is_published=false)だったシフトパターン割当を対象社員に公開する。
 */
class EmployeeShiftPublished implements DomainEvent
{
    public function __construct(
        public readonly int $employeeShiftAssignmentId,
        public readonly string $userId,
        public readonly string $workDate,
        public readonly string $publishedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'employee_shift.published';
    }

    public function payload(): array
    {
        return [
            'employee_shift_assignment_id' => $this->employeeShiftAssignmentId,
            'user_id' => $this->userId,
            'work_date' => $this->workDate,
            'published_by_user_id' => $this->publishedByUserId,
        ];
    }
}
