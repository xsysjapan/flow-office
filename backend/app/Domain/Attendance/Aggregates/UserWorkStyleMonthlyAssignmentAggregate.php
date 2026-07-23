<?php

namespace App\Domain\Attendance\Aggregates;

use App\Domain\Attendance\Events\UserWorkStyleAssignedForMonth;
use App\Domain\Attendance\Events\UserWorkStyleMonthlyAssignmentRemoved;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * user_work_style_monthly_assignment集約。主キー(user_work_style_monthly_assignments.id)は
 * コマンド側/呼び出し元サービスが決めたUUIDで、行の新規作成自体は
 * UserWorkStyleMonthlyAssignmentProjectorに委ねられる。ユーザー+年月の組で現在有効な
 * 割当は1件のみのため、Handlerは既存行があればそのidを再利用してretrieveする。
 */
class UserWorkStyleMonthlyAssignmentAggregate extends AggregateRoot
{
    public function assign(
        string $userId,
        string $yearMonth,
        string $workStyleId,
        string $assignedByUserId,
    ): self {
        $this->recordThat(new UserWorkStyleAssignedForMonth(
            userId: $userId,
            yearMonth: $yearMonth,
            workStyleId: $workStyleId,
            assignedByUserId: $assignedByUserId,
        ));

        return $this;
    }

    public function remove(
        string $userId,
        string $yearMonth,
        string $previousWorkStyleId,
        string $removedByUserId,
    ): self {
        $this->recordThat(new UserWorkStyleMonthlyAssignmentRemoved(
            userId: $userId,
            yearMonth: $yearMonth,
            previousWorkStyleId: $previousWorkStyleId,
            removedByUserId: $removedByUserId,
        ));

        return $this;
    }
}
