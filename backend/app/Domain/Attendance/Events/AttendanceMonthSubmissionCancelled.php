<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance_month.submission_cancelled
 *
 * UC-A010関連: 申請者自身が提出済み・差戻し済みの月次勤怠申請を取り消し、未提出へ戻す。
 */
class AttendanceMonthSubmissionCancelled extends ShouldBeStored
{
    public function __construct(
        public readonly string $cancelledByUserId,
    ) {}
}
