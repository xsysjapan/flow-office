<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * employee_rotation.assigned (指示書 8.5節: 社員のローテーション開始基準を設定する)。
 */
class EmployeeRotationAssigned implements DomainEvent
{
    public function __construct(
        public readonly int $employeeRotationAssignmentId,
        public readonly int $userId,
        public readonly int $rotationPatternId,
        public readonly string $rotationStartDate,
        public readonly int $rotationStartPosition,
        public readonly int $assignedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'employee_rotation.assigned';
    }

    public function payload(): array
    {
        return [
            'employee_rotation_assignment_id' => $this->employeeRotationAssignmentId,
            'user_id' => $this->userId,
            'rotation_pattern_id' => $this->rotationPatternId,
            'rotation_start_date' => $this->rotationStartDate,
            'rotation_start_position' => $this->rotationStartPosition,
            'assigned_by_user_id' => $this->assignedByUserId,
        ];
    }
}
