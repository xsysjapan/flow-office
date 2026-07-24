<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance_month.closed
 *
 * UC-A011: 管理部が月次勤怠を締める。
 */
class AttendanceMonthClosed extends ShouldBeStored
{
    public function __construct(
        public readonly string $closedByUserId,
    ) {}
}
