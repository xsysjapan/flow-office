<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\AssignUserWorkStyleForMonth;
use App\Domain\Attendance\Events\UserWorkStyleAssignedForMonth;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Models\UserWorkStyleMonthlyAssignment;

/**
 * ユーザーの月次働き方を割り当てる、または変更する。
 * 過去月の割当は変更せず、対象の年月(year_month)の行だけを追加・更新する。
 *
 * @implements CommandHandler<AssignUserWorkStyleForMonth>
 */
class AssignUserWorkStyleForMonthHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): UserWorkStyleMonthlyAssignment
    {
        assert($command instanceof AssignUserWorkStyleForMonth);

        $assignment = UserWorkStyleMonthlyAssignment::query()->updateOrCreate(
            ['user_id' => $command->userId, 'year_month' => $command->yearMonth],
            ['work_style_id' => $command->workStyleId, 'assigned_by_user_id' => $command->assignedByUserId],
        );

        $this->eventStore->append(
            aggregateType: 'user_work_style_monthly_assignment',
            aggregateId: (string) $assignment->id,
            event: new UserWorkStyleAssignedForMonth(
                userWorkStyleMonthlyAssignmentId: $assignment->id,
                userId: $command->userId,
                yearMonth: $command->yearMonth,
                workStyleId: $command->workStyleId,
                assignedByUserId: $command->assignedByUserId,
            ),
        );

        return $assignment;
    }
}
