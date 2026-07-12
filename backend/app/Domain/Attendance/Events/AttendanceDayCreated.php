<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * attendance.day_created
 *
 * 打刻・出勤操作を経由しない、任意の勤務日への出勤日の新規作成。打刻(attendance_punches)と
 * 出勤日(attendance_days)は勤務日が同じというだけの緩い関係しかなく、打刻の有無にかかわらず
 * 作成できる。
 */
class AttendanceDayCreated implements DomainEvent
{
    public function __construct(
        public readonly int $attendanceDayId,
        public readonly int $userId,
        public readonly string $workDate,
        public readonly string $reason,
        public readonly int $createdByUserId,
    ) {}

    public function eventType(): string
    {
        return 'attendance.day_created';
    }

    public function payload(): array
    {
        return [
            'attendance_day_id' => $this->attendanceDayId,
            'user_id' => $this->userId,
            'work_date' => $this->workDate,
            'reason' => $this->reason,
            'created_by_user_id' => $this->createdByUserId,
        ];
    }
}
