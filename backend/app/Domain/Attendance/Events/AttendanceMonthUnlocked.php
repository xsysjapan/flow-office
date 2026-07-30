<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance_month.unlocked (UC-A010)。差戻し時にAttendanceMonthLockedによるロックを解除する。
 */
class AttendanceMonthUnlocked extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $periodStartDate,
        public readonly string $periodEndDate,
        public readonly string $unlockedByUserId,
    ) {}
}
