<?php

namespace App\Domain\Device\Aggregates;

use App\Domain\Device\Events\DeviceDeleted;
use App\Domain\Device\Events\DeviceDisabled;
use App\Domain\Device\Events\DevicePaired;
use App\Domain\Device\Events\DevicePairingClaimIssued;
use App\Domain\Device\Events\DeviceRegistered;
use App\Domain\Device\Events\DeviceRevoked;
use App\Domain\Device\Events\DeviceRoleAssigned;
use App\Domain\Device\Events\DeviceScopeGranted;
use App\Domain\Device\Events\DeviceSettingsUpdated;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * device集約。主キーがコマンド側生成のUUID(このAggregateRootのuuid = devices.id)のため、
 * 行の新規作成自体もDeviceProjectorに委ねられる(docs/29-event-sourcing-framework-migration.md参照)。
 *
 * ステータス等の業務ルール判定はこの集約の再生状態ではなく、Handlerがdevices(Projection)の
 * 現在値を読んで行う。テストファクトリ等でdevicesの行を直接作成するケース(イベントを
 * 経由しないため集約の再生状態が空のまま)があり、Projectionの現在値の方が常に正しいため。
 * この集約はイベントの記録(recordThat/persist)専用に留める。
 */
class DeviceAggregate extends AggregateRoot
{
    /**
     * @param  array<int, string>  $roleTypes
     * @param  array<int, string>|null  $allowedPunchTypes
     */
    public function register(
        string $ownerType,
        ?string $ownerUserId,
        string $name,
        string $deviceType,
        array $roleTypes,
        ?string $siteId,
        ?string $locationName,
        ?string $defaultWorkLocationType,
        ?string $timezone,
        ?array $allowedPunchTypes,
        bool $allowOffline,
        bool $requireLocation,
        bool $autoDetectPunchType,
        string $registeredByUserId,
    ): self {
        $this->recordThat(new DeviceRegistered(
            ownerType: $ownerType,
            ownerUserId: $ownerUserId,
            name: $name,
            deviceType: $deviceType,
            roleTypes: $roleTypes,
            siteId: $siteId,
            locationName: $locationName,
            defaultWorkLocationType: $defaultWorkLocationType,
            timezone: $timezone,
            allowedPunchTypes: $allowedPunchTypes,
            allowOffline: $allowOffline,
            requireLocation: $requireLocation,
            autoDetectPunchType: $autoDetectPunchType,
            registeredByUserId: $registeredByUserId,
        ));

        return $this;
    }

    /**
     * @param  array<int, string>  $abilities
     */
    public function pair(array $abilities, string $pairedAt): self
    {
        $this->recordThat(new DevicePaired(abilities: $abilities, pairedAt: $pairedAt));

        return $this;
    }

    public function issuePairingClaim(string $issuedByUserId, bool $wasReissued): self
    {
        $this->recordThat(new DevicePairingClaimIssued(issuedByUserId: $issuedByUserId, wasReissued: $wasReissued));

        return $this;
    }

    public function disable(string $disabledByUserId, string $disabledAt): self
    {
        $this->recordThat(new DeviceDisabled(disabledByUserId: $disabledByUserId, disabledAt: $disabledAt));

        return $this;
    }

    public function revoke(string $revokedByUserId, ?string $reason, string $revokedAt): self
    {
        $this->recordThat(new DeviceRevoked(revokedByUserId: $revokedByUserId, reason: $reason, revokedAt: $revokedAt));

        return $this;
    }

    public function delete(string $deletedByUserId, string $deletedAt): self
    {
        $this->recordThat(new DeviceDeleted(deletedByUserId: $deletedByUserId, deletedAt: $deletedAt));

        return $this;
    }

    /**
     * @param  array<int, string>  $roleTypes
     */
    public function assignRoles(array $roleTypes, string $updatedByUserId): self
    {
        $this->recordThat(new DeviceRoleAssigned(roleTypes: $roleTypes, updatedByUserId: $updatedByUserId));

        return $this;
    }

    public function grantScope(string $scope, string $grantedByUserId): self
    {
        $this->recordThat(new DeviceScopeGranted(scope: $scope, grantedByUserId: $grantedByUserId));

        return $this;
    }

    /**
     * @param  array<int, string>|null  $allowedPunchTypes
     */
    public function updateSettings(
        string $name,
        ?string $siteId,
        ?string $locationName,
        ?string $defaultWorkLocationType,
        ?string $timezone,
        ?array $allowedPunchTypes,
        bool $allowOffline,
        bool $requireLocation,
        bool $autoDetectPunchType,
        string $updatedByUserId,
    ): self {
        $this->recordThat(new DeviceSettingsUpdated(
            name: $name,
            siteId: $siteId,
            locationName: $locationName,
            defaultWorkLocationType: $defaultWorkLocationType,
            timezone: $timezone,
            allowedPunchTypes: $allowedPunchTypes,
            allowOffline: $allowOffline,
            requireLocation: $requireLocation,
            autoDetectPunchType: $autoDetectPunchType,
            updatedByUserId: $updatedByUserId,
        ));

        return $this;
    }
}
