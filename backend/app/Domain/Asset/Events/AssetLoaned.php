<?php

namespace App\Domain\Asset\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * asset.loaned
 *
 * `self_service`/`backoffice`/`approval`すべてこの1つのイベントで表現する(spec 論点1)。
 * `loanRequestId`は`approval`方式で承認済み申請に基づいて貸与した場合のみ設定される
 * (nullable)。`loanId`はAssetProjectorが`asset_loans.id`としてそのまま使う。
 */
class AssetLoaned extends ShouldBeStored
{
    public function __construct(
        public readonly string $loanId,
        public readonly string $borrowerUserId,
        public readonly string $lentByUserId,
        public readonly ?string $expectedReturnAt,
        public readonly ?string $loanRequestId,
        public readonly string $loanedAt,
    ) {}
}
