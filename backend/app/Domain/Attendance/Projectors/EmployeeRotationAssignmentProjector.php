<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\Attendance\Events\EmployeeRotationAssigned;
use App\Models\EmployeeRotationAssignment;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * employee_rotation.assignedからemployee_rotation_assignmentsを作成・更新する
 * (.claude/skills/add-projection参照)。
 */
class EmployeeRotationAssignmentProjector extends Projector
{
    public function onEmployeeRotationAssigned(EmployeeRotationAssigned $event): void
    {
        $assignment = EmployeeRotationAssignment::query()->find($event->aggregateRootUuid())
            ?? new EmployeeRotationAssignment(['id' => $event->aggregateRootUuid()]);

        $assignment->fill([
            'user_id' => $event->userId,
            'rotation_pattern_id' => $event->rotationPatternId,
            'rotation_start_date' => $event->rotationStartDate,
            'rotation_start_position' => $event->rotationStartPosition,
            'assigned_by_user_id' => $event->assignedByUserId,
        ])->save();
    }
}
