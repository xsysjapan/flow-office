<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance_month.snapshot_recalculated
 *
 * 提出済み・承認済み・締め済みの月次勤怠について、集計ロジックの追加・修正を反映するため
 * snapshot_json(attendance_months.snapshot_json)を再計算する。対象月の日次勤怠は提出時に
 * ロックされ再計算対象外(差戻し中の月は対象にしない)なので、日次の実績値そのものは変わらず、
 * 同じ実績値からの集計結果だけが更新される(RecalculateAttendanceMonthSnapshotHandler参照)。
 */
class AttendanceMonthSnapshotRecalculated extends ShouldBeStored
{
    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function __construct(
        public readonly array $snapshot,
    ) {}
}
