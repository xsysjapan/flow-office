<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceSubmissionReminderExclusionAggregate;
use App\Domain\Attendance\Commands\ExcludeAttendanceSubmissionReminder;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceSubmissionReminderExclusion;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * 特定の社員×特定の年月を、勤怠未提出督促(WarnUnsubmittedAttendanceHandler)の対象から
 * 個別に除外する。既に同じ組み合わせの除外があれば、そのidを再利用して同一集約ストリームに
 * 追記する(reasonの更新として扱う)。
 *
 * @implements CommandHandler<ExcludeAttendanceSubmissionReminder>
 */
class ExcludeAttendanceSubmissionReminderHandler implements CommandHandler
{
    public function handle(Command $command): AttendanceSubmissionReminderExclusion
    {
        assert($command instanceof ExcludeAttendanceSubmissionReminder);

        if (! preg_match('/^\d{4}-\d{2}$/', $command->yearMonth)) {
            throw new DomainRuleException('yearMonthは YYYY-MM 形式で指定してください。');
        }

        if (trim($command->reason) === '') {
            throw new DomainRuleException('除外理由(reason)は必須です。');
        }

        User::query()->findOrFail($command->userId);

        $id = AttendanceSubmissionReminderExclusion::query()
            ->where('user_id', $command->userId)
            ->where('year_month', $command->yearMonth)
            ->value('id') ?? (string) Str::uuid();

        AttendanceSubmissionReminderExclusionAggregate::retrieve($id)
            ->exclude(
                userId: $command->userId,
                yearMonth: $command->yearMonth,
                reason: $command->reason,
                excludedByUserId: $command->excludedByUserId,
            )
            ->persist();

        return AttendanceSubmissionReminderExclusion::query()->findOrFail($id);
    }
}
