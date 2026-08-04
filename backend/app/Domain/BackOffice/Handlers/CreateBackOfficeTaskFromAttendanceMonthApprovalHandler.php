<?php

namespace App\Domain\BackOffice\Handlers;

use App\Domain\BackOffice\Aggregates\BackOfficeTaskAggregate;
use App\Domain\BackOffice\Commands\CreateBackOfficeTaskFromAttendanceMonthApproval;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\Notification\NotificationRecipients;
use App\Jobs\SendNotificationJob;
use App\Models\AttendanceMonth;
use App\Models\BackOfficeTask;
use App\Models\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * UC-A011/UC-B007: 月次勤怠の承認を受けてバックオフィスタスクを自動作成する。締め処理自体
 * (CloseAttendanceMonth)は変更せず、承認完了を起点に人事部の個別タスクとして起票するだけに
 * とどめる。対象月ごとの一括確認画面は持たず、社員×対象月ごとに個別のタスクとして締める
 * (docs/11-usecases-backoffice.md UC-B007)。
 *
 * @implements CommandHandler<CreateBackOfficeTaskFromAttendanceMonthApproval>
 */
class CreateBackOfficeTaskFromAttendanceMonthApprovalHandler implements CommandHandler
{
    private const TASK_TYPE = 'attendance_month_confirmation';

    private const ASSIGNED_DEPARTMENT = '人事部';

    public function handle(Command $command): BackOfficeTask
    {
        assert($command instanceof CreateBackOfficeTaskFromAttendanceMonthApproval);

        $month = AttendanceMonth::query()->with('user')->findOrFail($command->attendanceMonthId);

        $title = "月次勤怠確認: {$month->user?->name} ({$month->year_month})";

        $backOfficeTaskId = (string) Str::uuid();

        BackOfficeTaskAggregate::retrieve($backOfficeTaskId)
            ->create(
                sourceType: 'attendance_month',
                sourceId: $month->id,
                taskType: self::TASK_TYPE,
                title: $title,
                assignedDepartment: self::ASSIGNED_DEPARTMENT,
                dueOn: Carbon::now()->addDays(7)->toDateString(),
            )
            ->persist();

        $task = BackOfficeTask::query()->findOrFail($backOfficeTaskId);
        $summary = "「{$task->title}」が{$task->assigned_department}の未担当タスクに追加されました。";

        foreach (NotificationRecipients::byRoles([Role::HR_STAFF]) as $recipient) {
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
