<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\CreateShiftPattern;
use App\Domain\Attendance\Events\ShiftPatternCreated;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Models\ShiftPattern;

/**
 * UC-C004 手順2: シフトパターン(日勤/準夜勤/深夜勤/公休/明け休み等)を登録する。
 *
 * @implements CommandHandler<CreateShiftPattern>
 */
class CreateShiftPatternHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): ShiftPattern
    {
        assert($command instanceof CreateShiftPattern);

        $pattern = ShiftPattern::query()->create([
            'code' => $command->code,
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
            event: new ShiftPatternCreated(
                shiftPatternId: $pattern->id,
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
            ),
        );

        return $pattern;
    }
}
