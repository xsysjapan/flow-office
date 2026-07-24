<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\Attendance\Events\WorkStyleCreated;
use App\Domain\Attendance\Events\WorkStyleDefaultChanged;
use App\Models\WorkStyle;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * work_style.*イベントからwork_stylesを作成・更新する(.claude/skills/add-projection参照)。
 */
class WorkStyleProjector extends Projector
{
    public function onWorkStyleCreated(WorkStyleCreated $event): void
    {
        WorkStyle::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            $event->attributes,
        );
    }

    public function onWorkStyleDefaultChanged(WorkStyleDefaultChanged $event): void
    {
        if ($event->previousDefaultWorkStyleId !== null) {
            WorkStyle::query()->whereKey($event->previousDefaultWorkStyleId)->update(['is_default' => false]);
        }

        WorkStyle::query()->whereKey($event->aggregateRootUuid())->update(['is_default' => true]);
    }
}
