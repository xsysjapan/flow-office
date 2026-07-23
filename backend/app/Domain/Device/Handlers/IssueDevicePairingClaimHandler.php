<?php

namespace App\Domain\Device\Handlers;

use App\Domain\Device\Aggregates\DeviceAggregate;
use App\Domain\Device\Commands\IssueDevicePairingClaim;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Device;
use App\Models\DeviceOwnerType;
use App\Models\DeviceStatus;

/**
 * UC-D002: 共有端末の一時ペアリングトークン(claim token)を発行する。`device:claim-pairing`
 * ability のみを持つ短命(5分)のSanctumトークンで、業務APIは一切呼び出せない
 * (ClaimDevicePairingHandlerへの交換専用)。
 *
 * ペアリング済み(active)の端末についても、Androidアプリの削除など端末側の事情で
 * 打刻できなくなった場合に備え、管理者が再ペアリング用のclaim tokenを発行し直せる
 * ようにする。この場合、既存のトークンは失効し、端末は一旦pending_pairingへ戻る。
 *
 * @implements CommandHandler<IssueDevicePairingClaim>
 */
class IssueDevicePairingClaimHandler implements CommandHandler
{
    private const EXPIRES_IN_MINUTES = 5;

    /**
     * @return array{device: Device, claimToken: string}
     */
    public function handle(Command $command): array
    {
        assert($command instanceof IssueDevicePairingClaim);

        $device = Device::query()->findOrFail($command->deviceId);

        if ($device->owner_type !== DeviceOwnerType::ORGANIZATION_SHARED) {
            throw new DomainRuleException('共有端末以外にはペアリング用トークンを発行できません。');
        }

        if (! in_array($device->status, [DeviceStatus::PENDING_PAIRING, DeviceStatus::ACTIVE], true)) {
            throw new DomainRuleException('この端末は現在ペアリング(再ペアリング)できない状態です。');
        }

        $wasReissued = $device->status === DeviceStatus::ACTIVE;

        // 既に発行済みの未使用トークン、あるいは(再ペアリングの場合)稼働中だった本トークンを
        // 無効化してから発行し直す(QRを表示し直した際に古いトークンが使われてしまうことを防ぐ)。
        $device->tokens()->delete();

        $claimToken = $device->createToken(
            name: 'device-pairing-claim',
            abilities: ['device:claim-pairing'],
            expiresAt: now()->addMinutes(self::EXPIRES_IN_MINUTES),
        )->plainTextToken;

        // 誰の管理者権限でこの端末がアクティベーションされたかを記録する(管理者ICカードの
        // 初回登録・ブートストラップ判定に使う。docs/23-usecases-devices.md UC-D006)。
        DeviceAggregate::retrieve($device->id)
            ->issuePairingClaim($command->issuedByUserId, $wasReissued)
            ->persist();

        return ['device' => $device->refresh(), 'claimToken' => $claimToken];
    }
}
