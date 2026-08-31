<?php

namespace App\Domain\Asset\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * asset.registered
 *
 * 備品を新規登録する。AssetProjectorが行(assets)の新規作成自体を担当するため、
 * 再構築に必要な全フィールドを持たせる。
 */
class AssetRegistered extends ShouldBeStored
{
    public function __construct(
        public readonly string $assetNo,
        public readonly string $name,
        public readonly string $category,
        public readonly ?string $serialNumber,
        public readonly string $managementType,
        public readonly ?string $lendingMethod,
        public readonly ?string $defaultLocationText,
        public readonly string $qrToken,
        public readonly ?string $notes,
        public readonly string $registeredByUserId,
    ) {}
}
