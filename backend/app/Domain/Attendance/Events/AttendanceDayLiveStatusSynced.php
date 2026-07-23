<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance_day.live_status_synced
 *
 * UC-A012: 打刻ログがまだ矛盾なく1日分の勤務として組み立てられない間(出勤のみ・休憩開始のみ
 * 等)に、最新の打刻から`attendance_days.status`のみを画面の出退勤操作と同様に反映したことを
 * 表す。この日の出勤日行(attendance_days)がまだ存在しない場合、AttendanceDayProjectorが
 * 新規作成する必要があるため、userId/workDate/shiftAssignmentIdも持たせる
 * (既存行がある場合はProjector側で無視する)。actualStartAt/utcOffsetMinutesは、
 * 初めて勤務中(working)になった際の最初の出勤打刻時刻を反映する場合のみ設定される。
 */
class AttendanceDayLiveStatusSynced extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $workDate,
        public readonly ?string $shiftAssignmentId,
        public readonly string $status,
        public readonly string $source,
        public readonly ?string $actualStartAt,
        public readonly ?int $utcOffsetMinutes,
    ) {}
}
