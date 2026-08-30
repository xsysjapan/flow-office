<?php

namespace App\Domain\Asset\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * asset.deleted
 *
 * `stored_events`は削除しないが、Projection(`assets`他)からは物理削除する
 * (spec 論点9。指示書「EventStoreが履歴の正本であるため、削除時はRead Modelから
 * 対象備品を削除して構わない」)。
 */
class AssetDeleted extends ShouldBeStored
{
    public function __construct(
        public readonly string $deletedByUserId,
    ) {}
}
