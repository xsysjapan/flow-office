<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance_month.approved
 *
 * UC-A009: 承認者が月次勤怠を承認する。
 */
class AttendanceMonthApproved extends ShouldBeStored
{
    public function __construct(
        public readonly string $approvedByUserId,
    ) {}
}
