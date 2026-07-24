<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance_day.synced_from_punches
 *
 * UC-A012: 打刻ログが矛盾なく1日分の勤務として組み立てられたため、
 * 日次勤怠(attendance_days / attendance_breaks)に反映したことを表す。この日の出勤日行が
 * まだ存在しない場合、AttendanceDayProjectorが新規作成する必要があるため、
 * userId/workDate/shiftAssignmentIdも持たせる(既存行がある場合はProjector側で無視する)。
 * breaksはこのイベントの時点で有効な打刻から組み立てられた休憩の全件(置き換え)を表す。
 */
class AttendanceDaySyncedFromPunches extends ShouldBeStored
{
    /**
     * @param  array<int, array{start: string, end: string}>  $breaks
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $workDate,
        public readonly ?string $shiftAssignmentId,
        public readonly string $actualStartAt,
        public readonly string $actualEndAt,
        public readonly int $utcOffsetMinutes,
        public readonly ?string $workLocationType,
        public readonly array $breaks,
    ) {}
}
