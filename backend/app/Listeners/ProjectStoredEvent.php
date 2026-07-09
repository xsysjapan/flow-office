<?php

namespace App\Listeners;

use App\Domain\EventSourcing\StoredEventRecorded;
use Illuminate\Contracts\Container\Container;

/**
 * stored_events への追記のたびに、対象イベント種別を購読しているProjectorへ同期的に反映する。
 * 常駐ワーカーを前提にしないため(docs/02-tech-stack.md)、Projectionの更新は
 * リクエスト内で同期的に行う。
 */
class ProjectStoredEvent
{
    public function __construct(private readonly Container $container) {}

    public function handle(StoredEventRecorded $event): void
    {
        $projectorClasses = config('domain.projectors', []);

        foreach ($projectorClasses as $projectorClass) {
            $projector = $this->container->make($projectorClass);

            if (in_array($event->storedEvent->event_type, $projector->eventTypes(), true)) {
                $projector->project($event->storedEvent);
            }
        }
    }
}
