<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance.submission_reminder_excluded
 *
 * 特定の社員×特定の年月の組み合わせを、勤怠未提出督促(WarnUnsubmittedAttendanceHandler)の
 * 対象から個別に除外する。集約ID(attendance_submission_reminder_exclusions.id)は
 * `aggregateRootUuid()`から取得する。
 */
class AttendanceSubmissionReminderExcluded extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $yearMonth,
        public readonly string $reason,
        public readonly string $excludedByUserId,
    ) {}
}
