<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\Attendance\Events\ShiftPatternCreated;
use App\Domain\Attendance\Events\ShiftPatternUpdated;
use App\Models\ShiftPattern;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * shift_pattern.*イベントからshift_patternsを作成・更新する(.claude/skills/add-projection参照)。
 */
class ShiftPatternProjector extends Projector
{
    public function onShiftPatternCreated(ShiftPatternCreated $event): void
    {
        ShiftPattern::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'code' => $event->code,
                'name' => $event->name,
                'start_time' => $event->startTime,
                'end_time' => $event->endTime,
                'crosses_midnight' => $event->crossesMidnight,
                'break_minutes' => $event->breakMinutes,
                'break_start_time' => $event->breakStartTime,
                'break_end_time' => $event->breakEndTime,
                'prescribed_work_minutes' => $event->prescribedWorkMinutes,
            ],
        );
    }

    public function onShiftPatternUpdated(ShiftPatternUpdated $event): void
    {
        ShiftPattern::query()->whereKey($event->aggregateRootUuid())->update([
            'name' => $event->name,
            'start_time' => $event->startTime,
            'end_time' => $event->endTime,
            'crosses_midnight' => $event->crossesMidnight,
            'break_minutes' => $event->breakMinutes,
            'break_start_time' => $event->breakStartTime,
            'break_end_time' => $event->breakEndTime,
            'prescribed_work_minutes' => $event->prescribedWorkMinutes,
        ]);
    }
}
