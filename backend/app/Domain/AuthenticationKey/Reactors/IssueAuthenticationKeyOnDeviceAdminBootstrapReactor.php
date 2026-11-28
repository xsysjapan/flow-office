<?php

namespace App\Domain\AuthenticationKey\Reactors;

use App\Domain\AuthenticationKey\Commands\IssueAuthenticationKey;
use App\Domain\DeviceAdminSession\Events\DeviceAdminSessionStarted;
use App\Domain\EventSourcing\CommandBus;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * 端末の管理者の初回の登録(ブートストラップ)のセッション開始を受けて、同時に登録する認証キーを発行する。
 * 認証キーの情報(ID・種別・表示名・ハッシュ)を持たないセッション開始(NFC経由・旧イベント)は何もしない。
 */
class IssueAuthenticationKeyOnDeviceAdminBootstrapReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onDeviceAdminSessionStarted(DeviceAdminSessionStarted $event): void
    {
        if ($event->registeredKeyHash === null || $event->authenticationKeyId === null
            || $event->registeredKeyType === null || $event->registeredKeyDisplayName === null) {
            return;
        }

        $this->commandBus->dispatch(new IssueAuthenticationKey(
            userId: $event->adminUserId,
            keyType: $event->registeredKeyType,
            displayName: $event->registeredKeyDisplayName,
            rawKeyValue: null,
            validFrom: null,
            validUntil: null,
            metadata: null,
            registeredByUserId: $event->adminUserId,
            authenticationKeyId: $event->authenticationKeyId,
            keyHash: $event->registeredKeyHash,
            viaReactor: true,
        ));
    }
}
