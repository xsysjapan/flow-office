<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance_month.returned
 *
 * UC-A010: 承認者が月次勤怠を差戻しする。
 */
class AttendanceMonthReturned extends ShouldBeStored
{
    public function __construct(
        public readonly string $returnedByUserId,
        public readonly string $comment,
    ) {}
}
