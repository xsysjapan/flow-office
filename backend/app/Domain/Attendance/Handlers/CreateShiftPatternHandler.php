<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\ShiftPatternAggregate;
use App\Domain\Attendance\Commands\CreateShiftPattern;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\ShiftPattern;
use Illuminate\Support\Str;

/**
 * UC-C004 手順2: シフトパターン(日勤/準夜勤/深夜勤/公休/明け休み等)を登録する。
 *
 * @implements CommandHandler<CreateShiftPattern>
 */
class CreateShiftPatternHandler implements CommandHandler
{
    public function handle(Command $command): ShiftPattern
    {
        assert($command instanceof CreateShiftPattern);

        $id = (string) Str::uuid();

        ShiftPatternAggregate::retrieve($id)
            ->create(
                code: $command->code,
                name: $command->name,
                startTime: $command->startTime,
                endTime: $command->endTime,
                crossesMidnight: $command->crossesMidnight,
                breakMinutes: $command->breakMinutes,
                breakStartTime: $command->breakStartTime,
                breakEndTime: $command->breakEndTime,
                prescribedWorkMinutes: $command->prescribedWorkMinutes,
                createdByUserId: $command->createdByUserId,
            )
            ->persist();

        return ShiftPattern::query()->findOrFail($id);
    }
}
