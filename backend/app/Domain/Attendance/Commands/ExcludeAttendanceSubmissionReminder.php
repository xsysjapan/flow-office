<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 特定の社員×特定の年月について、勤怠未提出督促(WarnUnsubmittedAttendanceHandler)の対象から
 * 個別に除外する。「そもそもその月は提出対象ではなかった」という誤送信ケースなど、
 * `usage_start_date`/`hire_date`による除外条件では対応できない例外的なケース向けの汎用手段。
 */
class ExcludeAttendanceSubmissionReminder implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $yearMonth,
        public readonly string $reason,
        public readonly string $excludedByUserId,
    ) {}
}
