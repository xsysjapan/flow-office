<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * shift_pattern.updated (UC-C004 手順2: シフトパターンの内容を編集する)。
 */
class ShiftPatternUpdated implements DomainEvent
{
    public function __construct(
        public readonly int $shiftPatternId,
        public readonly string $name,
        public readonly ?string $startTime,
        public readonly ?string $endTime,
        public readonly bool $crossesMidnight,
        public readonly int $breakMinutes,
        public readonly ?string $breakStartTime,
        public readonly ?string $breakEndTime,
        public readonly int $prescribedWorkMinutes,
        public readonly int $updatedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'shift_pattern.updated';
    }

    public function payload(): array
    {
        return [
            'shift_pattern_id' => $this->shiftPatternId,
            'name' => $this->name,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'crosses_midnight' => $this->crossesMidnight,
            'break_minutes' => $this->breakMinutes,
            'break_start_time' => $this->breakStartTime,
            'break_end_time' => $this->breakEndTime,
            'prescribed_work_minutes' => $this->prescribedWorkMinutes,
            'updated_by_user_id' => $this->updatedByUserId,
        ];
    }
}
