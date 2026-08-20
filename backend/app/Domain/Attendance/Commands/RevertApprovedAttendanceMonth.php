<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 救済コマンド: バックオフィス担当者が「勤怠確定取消依頼」の承認後の処理として、
 * 承認済みの月次勤怠の確定を取り消す(approved→not_submitted)。
 */
class RevertApprovedAttendanceMonth implements Command
{
    public function __construct(
        public readonly string $attendanceMonthId,
        public readonly string $revertedByUserId,
        public readonly string $reason,
        public readonly string $workflowRequestId,
    ) {}
}
