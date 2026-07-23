<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * shift_pattern.updated (UC-C004 手順2: シフトパターンの内容を編集する)。
 */
class ShiftPatternUpdated extends ShouldBeStored
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $startTime,
        public readonly ?string $endTime,
        public readonly bool $crossesMidnight,
        public readonly int $breakMinutes,
        public readonly ?string $breakStartTime,
        public readonly ?string $breakEndTime,
        public readonly int $prescribedWorkMinutes,
        public readonly string $updatedByUserId,
    ) {}
}
