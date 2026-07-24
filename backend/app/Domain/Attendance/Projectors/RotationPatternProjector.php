<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\Attendance\Events\RotationPatternCreated;
use App\Models\RotationPattern;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * rotation_pattern.createdからrotation_patterns + rotation_pattern_itemsを作成する
 * (.claude/skills/add-projection参照)。rotation_pattern_itemsは独立した集約ではなく、
 * 親の再生成のたびにまとめて置き換える子行(work_calendar_daysと同じ扱い)。
 */
class RotationPatternProjector extends Projector
{
    public function onRotationPatternCreated(RotationPatternCreated $event): void
    {
        $pattern = RotationPattern::query()->find($event->aggregateRootUuid())
            ?? new RotationPattern(['id' => $event->aggregateRootUuid()]);

        $pattern->fill([
            'work_style_id' => $event->workStyleId,
            'name' => $event->name,
            'cycle_length' => $event->cycleLength,
        ])->save();

        $pattern->items()->delete();
        foreach ($event->items as $item) {
            $pattern->items()->create([
                'sequence' => $item['sequence'],
                'shift_pattern_id' => $item['shift_pattern_id'],
            ]);
        }
    }
}
