<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\UserWorkStyleMonthlyAssignmentAggregate;
use App\Domain\Attendance\Commands\AssignUserWorkStyleForMonth;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\UserWorkStyleMonthlyAssignment;
use Illuminate\Support\Str;

/**
 * ユーザーの月次働き方を割り当てる、または変更する。
 * 過去月の割当は変更せず、対象の年月(year_month)の行だけを追加・更新する
 * (既存行があればそのidを再利用して同一集約ストリームに追記する)。
 *
 * @implements CommandHandler<AssignUserWorkStyleForMonth>
 */
class AssignUserWorkStyleForMonthHandler implements CommandHandler
{
    public function handle(Command $command): UserWorkStyleMonthlyAssignment
    {
        assert($command instanceof AssignUserWorkStyleForMonth);

        $id = UserWorkStyleMonthlyAssignment::query()
            ->where('user_id', $command->userId)
            ->where('year_month', $command->yearMonth)
            ->value('id') ?? (string) Str::uuid();

        UserWorkStyleMonthlyAssignmentAggregate::retrieve($id)
            ->assign(
                userId: $command->userId,
                yearMonth: $command->yearMonth,
                workStyleId: $command->workStyleId,
                assignedByUserId: $command->assignedByUserId,
            )
            ->persist();

        return UserWorkStyleMonthlyAssignment::query()->findOrFail($id);
    }
}
