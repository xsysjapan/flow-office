<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance.month_confirmation_reverted
 *
 * 救済コマンド: 承認済みの月次勤怠の確定を取り消す(approved→not_submitted)。承認イベント自体は
 * 書き換えず、新しい状態遷移として記録する。workflowRequestIdは「勤怠確定取消依頼」の承認による
 * 取消のときだけ入り、確定取消の権限を持つ担当者による申請なしの直接取消ではnullになる。
 */
class AttendanceMonthConfirmationReverted extends ShouldBeStored
{
    public function __construct(
        public readonly string $revertedByUserId,
        public readonly string $reason,
        public readonly ?string $workflowRequestId,
    ) {}
}
