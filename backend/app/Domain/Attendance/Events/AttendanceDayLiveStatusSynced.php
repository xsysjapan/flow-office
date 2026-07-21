<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * UC-A012: 打刻ログがまだ矛盾なく1日分の勤務として組み立てられない間(出勤のみ・休憩開始のみ
 * 等)に、最新の打刻から`attendance_days.status`のみを画面の出退勤操作と同様に反映したことを
 * 表す。
 */
class AttendanceDayLiveStatusSynced implements DomainEvent
{
    public function __construct(
        public readonly int $attendanceDayId,
        public readonly string $status,
    ) {}

    public function eventType(): string
    {
        return 'attendance_day.live_status_synced';
    }

    public function payload(): array
    {
        return [
            'attendance_day_id' => $this->attendanceDayId,
            'status' => $this->status,
        ];
    }
}
