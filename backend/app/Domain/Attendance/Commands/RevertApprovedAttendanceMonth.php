<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 救済コマンド: 確定取消の権限(attendance.confirmation_revert)を持つ担当者が、承認済みの
 * 月次勤怠の確定を取り消す(approved→not_submitted)。workflowRequestIdは根拠となる申請が
 * ある場合だけ指定し、申請なしの直接取消ではnullにする。
 */
class RevertApprovedAttendanceMonth implements Command
{
    public function __construct(
        public readonly string $attendanceMonthId,
        public readonly string $revertedByUserId,
        public readonly string $reason,
        public readonly ?string $workflowRequestId = null,
    ) {}
}
