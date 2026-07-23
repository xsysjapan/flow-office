<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance_day.deleted
 *
 * UC-A015: 日次勤怠を削除する。AttendanceDayProjectorがこの行(breaks/leaveSegments/
 * daily_calculationsはFKのcascadeOnDeleteで併せて削除される)を削除する。
 */
class AttendanceDayDeleted extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $workDate,
        public readonly string $reason,
        public readonly string $deletedByUserId,
        public readonly string $punchLogAction,
    ) {}
}
