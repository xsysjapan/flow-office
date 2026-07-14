<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\UpdateShiftPattern;
use App\Domain\Attendance\Events\ShiftPatternUpdated;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Models\ShiftPattern;

/**
 * UC-C004 手順2: シフトパターンの内容を編集する。
 *
 * @implements CommandHandler<UpdateShiftPattern>
 */
class UpdateShiftPatternHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): ShiftPattern
    {
        assert($command instanceof UpdateShiftPattern);

        $pattern = ShiftPattern::query()->findOrFail($command->shiftPatternId);
        $pattern->update([
            'name' => $command->name,
            'start_time' => $command->startTime,
            'end_time' => $command->endTime,
            'crosses_midnight' => $command->crossesMidnight,
            'break_minutes' => $command->breakMinutes,
            'break_start_time' => $command->breakStartTime,
            'break_end_time' => $command->breakEndTime,
            'prescribed_work_minutes' => $command->prescribedWorkMinutes,
        ]);

        $this->eventStore->append(
            aggregateType: 'shift_pattern',
            aggregateId: (string) $pattern->id,
            event: new ShiftPatternUpdated(
                shiftPatternId: $pattern->id,
                name: $command->name,
                startTime: $command->startTime,
                endTime: $command->endTime,
                crossesMidnight: $command->crossesMidnight,
                breakMinutes: $command->breakMinutes,
                breakStartTime: $command->breakStartTime,
                breakEndTime: $command->breakEndTime,
                prescribedWorkMinutes: $command->prescribedWorkMinutes,
                updatedByUserId: $command->updatedByUserId,
            ),
        );

        return $pattern;
    }
}
