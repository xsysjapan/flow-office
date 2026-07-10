<?php

namespace App\Domain\EventSourcing;

use App\Models\StoredEvent;

/**
 * stored_events へのイベント追記後に発火する。Projectorはこれを購読して
 * Projection Tableを更新する (app/Listeners/ProjectStoredEvent.php)。
 */
class StoredEventRecorded
{
    public function __construct(public readonly StoredEvent $storedEvent) {}
}
