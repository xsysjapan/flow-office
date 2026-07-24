<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\ShiftPatternAggregate;
use App\Domain\Attendance\Commands\UpdateShiftPattern;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\ShiftPattern;

/**
 * UC-C004 手順2: シフトパターンの内容を編集する。
 *
 * @implements CommandHandler<UpdateShiftPattern>
 */
class UpdateShiftPatternHandler implements CommandHandler
{
    public function handle(Command $command): ShiftPattern
    {
        assert($command instanceof UpdateShiftPattern);

        ShiftPattern::query()->findOrFail($command->shiftPatternId);

        ShiftPatternAggregate::retrieve($command->shiftPatternId)
            ->update(
                name: $command->name,
                startTime: $command->startTime,
                endTime: $command->endTime,
                crossesMidnight: $command->crossesMidnight,
                breakMinutes: $command->breakMinutes,
                breakStartTime: $command->breakStartTime,
                breakEndTime: $command->breakEndTime,
                prescribedWorkMinutes: $command->prescribedWorkMinutes,
                updatedByUserId: $command->updatedByUserId,
            )
            ->persist();

        return ShiftPattern::query()->findOrFail($command->shiftPatternId);
    }
}
