<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance_month.submitted
 *
 * UC-A008: 月次勤怠を提出する。AttendanceMonthProjectorが行の新規作成(初回提出時)自体を
 * 担当するため、userId/yearMonthも持たせる。snapshotは提出時点の集計スナップショット
 * (attendance_months.snapshot_json)で、提出後に日次実績が再計算されても保持される。
 */
class AttendanceMonthSubmitted extends ShouldBeStored
{
    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $yearMonth,
        public readonly string $approverUserId,
        public readonly array $snapshot,
    ) {}
}
