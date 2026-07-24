<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\Attendance\Events\UserWorkStyleAssignedForMonth;
use App\Domain\Attendance\Events\UserWorkStyleMonthlyAssignmentRemoved;
use App\Models\UserWorkStyleMonthlyAssignment;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * user_work_style_monthly_assignment.*からuser_work_style_monthly_assignmentsを作成・更新・
 * 削除する(.claude/skills/add-projection参照)。
 */
class UserWorkStyleMonthlyAssignmentProjector extends Projector
{
    public function onUserWorkStyleAssignedForMonth(UserWorkStyleAssignedForMonth $event): void
    {
        $assignment = UserWorkStyleMonthlyAssignment::query()->find($event->aggregateRootUuid())
            ?? new UserWorkStyleMonthlyAssignment(['id' => $event->aggregateRootUuid()]);

        $assignment->fill([
            'user_id' => $event->userId,
            'year_month' => $event->yearMonth,
            'work_style_id' => $event->workStyleId,
            'assigned_by_user_id' => $event->assignedByUserId,
        ])->save();
    }

    public function onUserWorkStyleMonthlyAssignmentRemoved(UserWorkStyleMonthlyAssignmentRemoved $event): void
    {
        UserWorkStyleMonthlyAssignment::query()->find($event->aggregateRootUuid())?->delete();
    }
}
