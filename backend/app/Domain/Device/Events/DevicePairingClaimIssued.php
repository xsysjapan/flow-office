<?php

namespace App\Domain\Device\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 共有端末の一時ペアリングトークン(claim token)を発行した(docs/23-usecases-devices.md
 * UC-D002)。初回発行・再発行のどちらでも必ず記録する(activated_by_user_idの変更を
 * Projectionへ反映するため)。$wasReissuedがtrueの場合のみ、ペアリング済み(active)から
 * pending_pairingへ状態が戻る(旧device.pairing_reissuedを統合)。
 */
class DevicePairingClaimIssued extends ShouldBeStored
{
    public function __construct(
        public readonly int $issuedByUserId,
        public readonly bool $wasReissued,
    ) {}
}
