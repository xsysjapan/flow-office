<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\AssignEmployeeRotation;
use App\Domain\Attendance\Events\EmployeeRotationAssigned;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Models\EmployeeRotationAssignment;

/**
 * 指示書 8.5節: 社員ごとのローテーション開始基準(どのパターンを、いつ、周期の何番目から
 * 適用するか)を設定する。1人につき現在有効な基準は1件のみとし、切り替え時は上書きする。
 *
 * @implements CommandHandler<AssignEmployeeRotation>
 */
class AssignEmployeeRotationHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): EmployeeRotationAssignment
    {
        assert($command instanceof AssignEmployeeRotation);

        $assignment = EmployeeRotationAssignment::query()->updateOrCreate(
            ['user_id' => $command->userId],
            [
                'rotation_pattern_id' => $command->rotationPatternId,
                'rotation_start_date' => $command->rotationStartDate,
                'rotation_start_position' => $command->rotationStartPosition,
                'assigned_by_user_id' => $command->assignedByUserId,
            ],
        );

        $this->eventStore->append(
            aggregateType: 'employee_rotation_assignment',
            aggregateId: (string) $assignment->id,
            event: new EmployeeRotationAssigned(
                employeeRotationAssignmentId: $assignment->id,
                userId: $command->userId,
                rotationPatternId: $command->rotationPatternId,
                rotationStartDate: $command->rotationStartDate,
                rotationStartPosition: $command->rotationStartPosition,
                assignedByUserId: $command->assignedByUserId,
            ),
        );

        return $assignment;
    }
}
