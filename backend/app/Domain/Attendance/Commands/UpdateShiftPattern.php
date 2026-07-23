<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class UpdateShiftPattern implements Command
{
    public function __construct(
        public readonly string $shiftPatternId,
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
