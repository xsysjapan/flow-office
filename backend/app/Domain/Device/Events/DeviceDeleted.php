<?php

namespace App\Domain\Device\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 監査証跡(stored_events、UC-M003)は残すため物理削除はせず、DeviceProjectorが
 * devices.deleted_atを設定する論理削除のみ行う。
 */
class DeviceDeleted extends ShouldBeStored
{
    public function __construct(
        public readonly string $deletedByUserId,
        public readonly string $deletedAt,
    ) {}
}
