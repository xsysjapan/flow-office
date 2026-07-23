<?php

namespace App\Domain\Attendance\Aggregates;

use App\Domain\Attendance\Events\ShiftPatternCreated;
use App\Domain\Attendance\Events\ShiftPatternUpdated;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * shift_pattern集約。主キー(shift_patterns.id)はコマンド側が決めたUUIDで、行の新規作成自体は
 * ShiftPatternProjectorに委ねられる。
 */
class ShiftPatternAggregate extends AggregateRoot
{
    public function create(
        string $code,
        string $name,
        ?string $startTime,
        ?string $endTime,
        bool $crossesMidnight,
        int $breakMinutes,
        ?string $breakStartTime,
        ?string $breakEndTime,
        int $prescribedWorkMinutes,
        string $createdByUserId,
    ): self {
        $this->recordThat(new ShiftPatternCreated(
            code: $code,
            name: $name,
            startTime: $startTime,
            endTime: $endTime,
            crossesMidnight: $crossesMidnight,
            breakMinutes: $breakMinutes,
            breakStartTime: $breakStartTime,
            breakEndTime: $breakEndTime,
            prescribedWorkMinutes: $prescribedWorkMinutes,
            createdByUserId: $createdByUserId,
        ));

        return $this;
    }

    public function update(
        string $name,
        ?string $startTime,
        ?string $endTime,
        bool $crossesMidnight,
        int $breakMinutes,
        ?string $breakStartTime,
        ?string $breakEndTime,
        int $prescribedWorkMinutes,
        string $updatedByUserId,
    ): self {
        $this->recordThat(new ShiftPatternUpdated(
            name: $name,
            startTime: $startTime,
            endTime: $endTime,
            crossesMidnight: $crossesMidnight,
            breakMinutes: $breakMinutes,
            breakStartTime: $breakStartTime,
            breakEndTime: $breakEndTime,
            prescribedWorkMinutes: $prescribedWorkMinutes,
            updatedByUserId: $updatedByUserId,
        ));

        return $this;
    }
}
