<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-A010関連: 申請者自身が、提出済み・差戻し済みの月次勤怠申請(workflow_request)を
 * 取り消した際に、対象の月次勤怠を未提出へ戻す。
 */
class CancelSubmittedAttendanceMonth implements Command
{
    public function __construct(
        public readonly string $attendanceMonthId,
        public readonly string $cancelledByUserId,
    ) {}
}
