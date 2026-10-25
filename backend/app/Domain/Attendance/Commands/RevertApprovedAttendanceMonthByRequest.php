<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 「勤怠確定取消依頼」(汎用申請ワークフロー)が承認された時点で、申請者本人の月次勤怠の
 * 確定を取り消す(approved→not_submitted)。ワークフロー側が勤怠のテーブルを読まずに済むよう、
 * 月次勤怠のIDではなく(userId, yearMonth)で対象を指定する。
 */
class RevertApprovedAttendanceMonthByRequest implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $yearMonth,
        public readonly string $reason,
        public readonly string $workflowRequestId,
        public readonly ?string $revertedByUserId,
    ) {}
}
