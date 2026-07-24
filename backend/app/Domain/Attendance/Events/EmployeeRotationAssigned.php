<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * employee_rotation.assigned (指示書 8.5節: 社員のローテーション開始基準を設定する)。
 * 集約ID(employee_rotation_assignments.id)は`aggregateRootUuid()`から取得する。
 */
class EmployeeRotationAssigned extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $rotationPatternId,
        public readonly string $rotationStartDate,
        public readonly int $rotationStartPosition,
        public readonly string $assignedByUserId,
    ) {}
}
