<?php

namespace App\Domain\Attendance\Aggregates;

use App\Domain\Attendance\Events\EmployeeRotationAssigned;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * employee_rotation_assignment集約。主キー(employee_rotation_assignments.id)はコマンド側/
 * 呼び出し元サービスが決めたUUIDで、行の新規作成自体はEmployeeRotationAssignmentProjectorに
 * 委ねられる。1人につき現在有効な基準は1件のみのため、Handlerは既存行があればそのidを
 * 再利用してretrieveする(同一集約ストリームへの追記として扱う)。
 */
class EmployeeRotationAssignmentAggregate extends AggregateRoot
{
    public function assign(
        string $userId,
        string $rotationPatternId,
        string $rotationStartDate,
        int $rotationStartPosition,
        string $assignedByUserId,
    ): self {
        $this->recordThat(new EmployeeRotationAssigned(
            userId: $userId,
            rotationPatternId: $rotationPatternId,
            rotationStartDate: $rotationStartDate,
            rotationStartPosition: $rotationStartPosition,
            assignedByUserId: $assignedByUserId,
        ));

        return $this;
    }
}
