<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance.month_confirmation_reverted
 *
 * 救済コマンド: 「勤怠確定取消依頼」(汎用申請ワークフロー)の承認後、バックオフィス担当者が
 * 承認済みの月次勤怠の確定を取り消す(approved→not_submitted)。承認イベント自体は書き換えず、
 * 新しい状態遷移として記録する。
 */
class AttendanceMonthConfirmationReverted extends ShouldBeStored
{
    public function __construct(
        public readonly string $revertedByUserId,
        public readonly string $reason,
        public readonly string $workflowRequestId,
    ) {}
}
