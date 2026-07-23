<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * shift_pattern.created (UC-C004 手順2: シフトパターン(日勤/準夜勤/深夜勤/公休/明け休み等)を登録する)。
 * 集約ID(shift_patterns.id)は`aggregateRootUuid()`から取得する。
 */
class ShiftPatternCreated extends ShouldBeStored
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $startTime,
        public readonly ?string $endTime,
        public readonly bool $crossesMidnight,
        public readonly int $breakMinutes,
        public readonly ?string $breakStartTime,
        public readonly ?string $breakEndTime,
        public readonly int $prescribedWorkMinutes,
        public readonly string $createdByUserId,
    ) {}
}
