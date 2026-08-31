<?php

namespace App\Domain\Asset\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/** asset.qr_code_reissued。qr_tokenのみ差し替える(asset_no・履歴は変更しない、spec 論点7)。 */
class AssetQrCodeReissued extends ShouldBeStored
{
    public function __construct(
        public readonly string $qrToken,
        public readonly string $reissuedByUserId,
    ) {}
}
