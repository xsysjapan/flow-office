<?php

namespace App\Domain\BackOffice\Handlers;

use App\Domain\BackOffice\Aggregates\BackOfficeTaskAggregate;
use App\Domain\BackOffice\Commands\CreateBackOfficeTaskFromExpenseClaimApproval;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\Notification\NotificationRecipients;
use App\Jobs\SendNotificationJob;
use App\Models\BackOfficeTask;
use App\Models\ExpenseClaim;
use App\Models\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * UC-X012: 経費精算の承認を受けてバックオフィスタスクを自動作成する。
 * task_typeは経費区分によらず'expense_reimbursement'固定(勘定科目のマッピングは
 * expense_categories側の設定で吸収するため。docs/30-usecases-expense.md参照)。
 *
 * @implements CommandHandler<CreateBackOfficeTaskFromExpenseClaimApproval>
 */
class CreateBackOfficeTaskFromExpenseClaimApprovalHandler implements CommandHandler
{
    private const TASK_TYPE = 'expense_reimbursement';

    private const ASSIGNED_DEPARTMENT = '経理部';

    public function handle(Command $command): BackOfficeTask
    {
        assert($command instanceof CreateBackOfficeTaskFromExpenseClaimApproval);

        $claim = ExpenseClaim::query()->with('employee')->findOrFail($command->expenseClaimId);

        $title = "経費精算: {$claim->employee?->name} ({$claim->period_from->toDateString()}〜{$claim->period_to->toDateString()})";

        $backOfficeTaskId = (string) Str::uuid();

        BackOfficeTaskAggregate::retrieve($backOfficeTaskId)
            ->create(
                sourceType: 'expense_claim',
                sourceId: $claim->id,
                taskType: self::TASK_TYPE,
                title: $title,
                assignedDepartment: self::ASSIGNED_DEPARTMENT,
                dueOn: Carbon::now()->addDays(7)->toDateString(),
            )
            ->persist();

        $task = BackOfficeTask::query()->findOrFail($backOfficeTaskId);
        $summary = "「{$task->title}」が{$task->assigned_department}の未担当タスクに追加されました。";

        foreach (NotificationRecipients::byRoles([Role::ACCOUNTING_STAFF]) as $recipient) {
            SendNotificationJob::enqueue(
                recipient: $recipient,
                title: 'バックオフィスタスク作成',
                summary: $summary,
                detailUrl: null,
            );
        }

        return $task;
    }
}
