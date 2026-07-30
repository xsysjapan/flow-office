<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance_month.locked (UC-A008)。提出時に対象月(period_start_date〜period_end_date)の
 * 日次勤怠を編集不可にする。差戻し(AttendanceMonthReturned)によりAttendanceMonthUnlockedが
 * 記録されるまで解除されない。AttendanceMonthProjectorの反映処理でattendance_locksへ
 * 追記される(App\Models\AttendanceLock)。
 */
class AttendanceMonthLocked extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $periodStartDate,
        public readonly string $periodEndDate,
        public readonly string $lockedByUserId,
        public readonly ?string $workflowRequestId = null,
    ) {}
}
