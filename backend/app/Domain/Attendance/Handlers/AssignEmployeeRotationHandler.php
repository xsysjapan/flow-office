<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\EmployeeRotationAssignmentAggregate;
use App\Domain\Attendance\Commands\AssignEmployeeRotation;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\EmployeeRotationAssignment;
use Illuminate\Support\Str;

/**
 * 指示書 8.5節: 社員ごとのローテーション開始基準(どのパターンを、いつ、周期の何番目から
 * 適用するか)を設定する。1人につき現在有効な基準は1件のみとし、切り替え時は上書きする
 * (既存行があればそのidを再利用して同一集約ストリームに追記する)。
 *
 * @implements CommandHandler<AssignEmployeeRotation>
 */
class AssignEmployeeRotationHandler implements CommandHandler
{
    public function handle(Command $command): EmployeeRotationAssignment
    {
        assert($command instanceof AssignEmployeeRotation);

        $id = EmployeeRotationAssignment::query()
            ->where('user_id', $command->userId)
            ->value('id') ?? (string) Str::uuid();

        EmployeeRotationAssignmentAggregate::retrieve($id)
            ->assign(
                userId: $command->userId,
                rotationPatternId: $command->rotationPatternId,
                rotationStartDate: $command->rotationStartDate,
                rotationStartPosition: $command->rotationStartPosition,
                assignedByUserId: $command->assignedByUserId,
            )
            ->persist();

        return EmployeeRotationAssignment::query()->findOrFail($id);
    }
}
