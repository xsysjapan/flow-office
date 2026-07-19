<?php

namespace App\Domain\Device\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * ペアリング済み(active)の端末に対し、Androidアプリの削除等に備えて管理者が
 * 再ペアリング用のclaim tokenを発行し直した(docs/23-usecases-devices.md UC-D002)。
 * 端末は再度pending_pairingへ戻り、ClaimDevicePairingHandlerでactiveに復帰する。
 */
class DevicePairingReissued implements DomainEvent
{
    public function __construct(
        public readonly int $deviceId,
        public readonly int $issuedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'device.pairing_reissued';
    }

    public function payload(): array
    {
        return [
            'device_id' => $this->deviceId,
            'issued_by_user_id' => $this->issuedByUserId,
        ];
    }
}
