<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\Attendance\Events\WorkStyleCreated;
use App\Domain\Attendance\Events\WorkStyleDefaultChanged;
use App\Domain\Attendance\Events\WorkStyleUpdated;
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
            $this->normalizeAttributes($event->attributes),
        );
    }

    public function onWorkStyleDefaultChanged(WorkStyleDefaultChanged $event): void
    {
        if ($event->previousDefaultWorkStyleId !== null) {
            WorkStyle::query()->whereKey($event->previousDefaultWorkStyleId)->update(['is_default' => false]);
        }

        WorkStyle::query()->whereKey($event->aggregateRootUuid())->update(['is_default' => true]);
    }

    public function onWorkStyleUpdated(WorkStyleUpdated $event): void
    {
        WorkStyle::query()->whereKey($event->aggregateRootUuid())->update($this->normalizeAttributes($event->attributes));
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function normalizeAttributes(array $attributes): array
    {
        // company_calendarsへの改称前のイベントではcalendar_idとして保存されている。
        if (! array_key_exists('company_calendar_id', $attributes) && array_key_exists('calendar_id', $attributes)) {
            $attributes['company_calendar_id'] = $attributes['calendar_id'];
        }

        // 過去イベントに残る廃止済み列を、現行Projectionへ書き込まない。
        return array_intersect_key($attributes, array_flip((new WorkStyle)->getFillable()));
    }
}
