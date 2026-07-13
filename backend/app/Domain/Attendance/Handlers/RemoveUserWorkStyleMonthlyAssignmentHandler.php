<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\RemoveUserWorkStyleMonthlyAssignment;
use App\Domain\Attendance\Events\UserWorkStyleMonthlyAssignmentRemoved;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\SystemSetting;
use App\Models\UserWorkStyleMonthlyAssignment;
use Illuminate\Support\Carbon;

/**
 * 指示書 13章: 社員個別の働き方指定を取り消し、「会社のデフォルトを使用」の状態
 * (該当月にuser_work_style_monthly_assignmentsの行が無い状態)に戻す。
 * 過去月の履歴は変更させないため、対象年月が今月より前の場合は取り消せない。
 *
 * @implements CommandHandler<RemoveUserWorkStyleMonthlyAssignment>
 */
class RemoveUserWorkStyleMonthlyAssignmentHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof RemoveUserWorkStyleMonthlyAssignment);

        $assignment = UserWorkStyleMonthlyAssignment::query()->findOrFail($command->assignmentId);

        $currentYearMonth = Carbon::now(SystemSetting::current()->default_timezone)->format('Y-m');
        if ($assignment->year_month < $currentYearMonth) {
            throw new DomainRuleException('過去月の割当は取り消せません(履歴として保持されます)。');
        }

        $previousWorkStyleId = $assignment->work_style_id;
        $userId = $assignment->user_id;
        $yearMonth = $assignment->year_month;

        $assignment->delete();

        $this->eventStore->append(
            aggregateType: 'user_work_style_monthly_assignment',
            aggregateId: (string) $command->assignmentId,
            event: new UserWorkStyleMonthlyAssignmentRemoved(
                assignmentId: $command->assignmentId,
                userId: $userId,
                yearMonth: $yearMonth,
                previousWorkStyleId: $previousWorkStyleId,
                removedByUserId: $command->removedByUserId,
            ),
        );

        return null;
    }
}
