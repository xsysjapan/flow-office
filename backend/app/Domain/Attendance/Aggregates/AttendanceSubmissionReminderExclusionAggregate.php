<?php

namespace App\Domain\Attendance\Aggregates;

use App\Domain\Attendance\Events\AttendanceSubmissionReminderExcluded;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * attendance_submission_reminder_exclusion集約。主キー
 * (attendance_submission_reminder_exclusions.id)はコマンド側/呼び出し元サービスが決めたUUIDで、
 * 行の新規作成自体はAttendanceSubmissionReminderExclusionProjectorに委ねられる。
 * ユーザー+年月の組で除外は1件のみのため、Handlerは既存行があればそのidを再利用して
 * retrieveする(再指定は同一集約ストリームへの追記)。
 */
class AttendanceSubmissionReminderExclusionAggregate extends AggregateRoot
{
    public function exclude(
        string $userId,
        string $yearMonth,
        string $reason,
        string $excludedByUserId,
    ): self {
        $this->recordThat(new AttendanceSubmissionReminderExcluded(
            userId: $userId,
            yearMonth: $yearMonth,
            reason: $reason,
            excludedByUserId: $excludedByUserId,
        ));

        return $this;
    }
}
