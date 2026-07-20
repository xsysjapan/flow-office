<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * 退勤時、働き方(work_styles.auto_break_enabled)の設定に基づき、打刻・記録された休憩が
 * 一切無い日に標準休憩(default_break_start_time〜default_break_end_time)を自動で
 * 補完した (ClockOutHandler参照)。ユーザーが実際に打刻・編集した休憩を上書きすることはない。
 */
class AttendanceBreakAutoInserted implements DomainEvent
{
    public function __construct(
        public readonly int $attendanceDayId,
        public readonly int $attendanceBreakId,
        public readonly int $workStyleId,
        public readonly string $breakStartAt,
        public readonly string $breakEndAt,
    ) {}

    public function eventType(): string
    {
        return 'attendance.break_auto_inserted';
    }

    public function payload(): array
    {
        return [
            'attendance_day_id' => $this->attendanceDayId,
            'attendance_break_id' => $this->attendanceBreakId,
            'work_style_id' => $this->workStyleId,
            'break_start_at' => $this->breakStartAt,
            'break_end_at' => $this->breakEndAt,
        ];
    }
}
