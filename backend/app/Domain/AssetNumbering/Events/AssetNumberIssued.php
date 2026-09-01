<?php

namespace App\Domain\AssetNumbering\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * asset_number.issued
 *
 * 採番ルールから管理番号が1件払い出されたことを表す監査イベント。
 */
class AssetNumberIssued extends ShouldBeStored
{
    public function __construct(
        public readonly int $assetNumberRuleId,
        public readonly string $category,
        public readonly int $issuedNumber,
        public readonly string $assetNo,
        public readonly string $actorUserId,
    ) {}
}
