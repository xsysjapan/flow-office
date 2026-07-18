<?php

namespace App\Domain\Device\Handlers;

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

        if ($device->status !== DeviceStatus::PENDING_PAIRING) {
            throw new DomainRuleException('この端末は現在ペアリング待ち状態ではありません。');
        }

        // 既に発行済みの未使用トークンが残っていれば無効化してから発行し直す
        // (QRを表示し直した際に古いトークンが使われてしまうことを防ぐ)。
        $device->tokens()->delete();

        $claimToken = $device->createToken(
            name: 'device-pairing-claim',
            abilities: ['device:claim-pairing'],
            expiresAt: now()->addMinutes(self::EXPIRES_IN_MINUTES),
        )->plainTextToken;

        return ['device' => $device, 'claimToken' => $claimToken];
    }
}
