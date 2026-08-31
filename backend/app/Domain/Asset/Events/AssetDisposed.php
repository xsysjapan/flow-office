<?php

namespace App\Domain\Asset\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * asset.disposed
 *
 * 廃棄は削除と異なりProjection上の行は残し、status=disposedのまま一覧・検索対象にも
 * 表示する(spec「削除 vs 廃棄」)。
 */
class AssetDisposed extends ShouldBeStored
{
    public function __construct(
        public readonly ?string $note,
        public readonly string $disposedByUserId,
    ) {}
}
