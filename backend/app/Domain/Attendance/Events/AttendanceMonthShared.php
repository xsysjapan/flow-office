<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance_month.shared (UC-A008)。提出時に対象月の日次勤怠一式を承認者へ開示したことを
 * 表す。AttendanceMonthProjectorの反映処理でentity_sharesへ追記される
 * (App\Models\EntityShare。shareable_type='attendance_month', shareable_id=attendance_months.id)。
 */
class AttendanceMonthShared extends ShouldBeStored
{
    public function __construct(
        public readonly string $sharedWithUserId,
        public readonly string $sharedByUserId,
    ) {}
}
