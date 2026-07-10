<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * UC-A012: 打刻ログが矛盾なく1日分の勤務として組み立てられたため、
 * 日次勤怠(attendance_days / attendance_breaks)に反映したことを表す。
 */
class AttendanceDaySyncedFromPunches implements DomainEvent
{
    public function __construct(
        public readonly int $attendanceDayId,
        public readonly string $actualStartAt,
        public readonly string $actualEndAt,
    ) {}

    public function eventType(): string
    {
        return 'attendance_day.synced_from_punches';
    }

    public function payload(): array
    {
        return [
            'attendance_day_id' => $this->attendanceDayId,
            'actual_start_at' => $this->actualStartAt,
            'actual_end_at' => $this->actualEndAt,
        ];
    }
}
