<?php

namespace App\Domain\Device\Projectors;

use App\Domain\Device\Events\DeviceDeleted;
use App\Domain\Device\Events\DeviceDisabled;
use App\Domain\Device\Events\DevicePaired;
use App\Domain\Device\Events\DevicePairingClaimIssued;
use App\Domain\Device\Events\DeviceRegistered;
use App\Domain\Device\Events\DeviceRevoked;
use App\Domain\Device\Events\DeviceRoleAssigned;
use App\Domain\Device\Events\DeviceScopeGranted;
use App\Domain\Device\Events\DeviceSettingsUpdated;
use App\Models\Device;
use App\Models\DeviceStatus;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * device.* イベントから devices / device_roles / device_scopes を作成・更新する。主キーが
 * コマンド側生成のUUID(event->aggregateRootUuid())のため、行の新規作成自体もこのProjectorが
 * 担う(docs/29-event-sourcing-framework-migration.md参照)。
 */
class DeviceProjector extends Projector
{
    public function onDeviceRegistered(DeviceRegistered $event): void
    {
        $device = Device::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'owner_type' => $event->ownerType,
                'owner_user_id' => $event->ownerUserId,
                'name' => $event->name,
                'device_type' => $event->deviceType,
                'status' => DeviceStatus::PENDING_PAIRING,
                'site_id' => $event->siteId,
                'location_name' => $event->locationName,
                'default_work_location_type' => $event->defaultWorkLocationType,
                'timezone' => $event->timezone,
                'allowed_punch_types' => $event->allowedPunchTypes,
                'allow_offline' => $event->allowOffline,
                'require_location' => $event->requireLocation,
                'auto_detect_punch_type' => $event->autoDetectPunchType,
            ],
        );

        foreach ($event->roleTypes as $roleType) {
            $device->roles()->create(['role_type' => $roleType]);
        }
    }

    public function onDevicePaired(DevicePaired $event): void
    {
        $this->device($event->aggregateRootUuid())->update([
            'status' => DeviceStatus::ACTIVE,
            'paired_at' => $event->pairedAt,
        ]);
    }

    public function onDevicePairingClaimIssued(DevicePairingClaimIssued $event): void
    {
        $attributes = ['activated_by_user_id' => $event->issuedByUserId];

        if ($event->wasReissued) {
            $attributes['status'] = DeviceStatus::PENDING_PAIRING;
        }

        $this->device($event->aggregateRootUuid())->update($attributes);
    }

    public function onDeviceDisabled(DeviceDisabled $event): void
    {
        $this->device($event->aggregateRootUuid())->update([
            'status' => DeviceStatus::DISABLED,
            'disabled_at' => $event->disabledAt,
        ]);
    }

    public function onDeviceRevoked(DeviceRevoked $event): void
    {
        $this->device($event->aggregateRootUuid())->update([
            'status' => DeviceStatus::REVOKED,
            'revoked_at' => $event->revokedAt,
        ]);
    }

    public function onDeviceDeleted(DeviceDeleted $event): void
    {
        // 監査証跡(stored_events)は残すため物理削除はせず、論理削除のみ行う。
        Device::withTrashed()
            ->whereKey($event->aggregateRootUuid())
            ->update(['deleted_at' => $event->deletedAt]);
    }

    public function onDeviceRoleAssigned(DeviceRoleAssigned $event): void
    {
        $device = $this->device($event->aggregateRootUuid());

        $device->roles()->whereNotIn('role_type', $event->roleTypes)->delete();
        foreach ($event->roleTypes as $roleType) {
            $device->roles()->firstOrCreate(['role_type' => $roleType]);
        }
    }

    public function onDeviceScopeGranted(DeviceScopeGranted $event): void
    {
        $this->device($event->aggregateRootUuid())->scopes()->firstOrCreate(['scope' => $event->scope]);
    }

    public function onDeviceSettingsUpdated(DeviceSettingsUpdated $event): void
    {
        $this->device($event->aggregateRootUuid())->update([
            'name' => $event->name,
            'site_id' => $event->siteId,
            'location_name' => $event->locationName,
            'default_work_location_type' => $event->defaultWorkLocationType,
            'timezone' => $event->timezone,
            'allowed_punch_types' => $event->allowedPunchTypes,
            'allow_offline' => $event->allowOffline,
            'require_location' => $event->requireLocation,
            'auto_detect_punch_type' => $event->autoDetectPunchType,
        ]);
    }

    private function device(?string $aggregateUuid): Device
    {
        return Device::query()->findOrFail($aggregateUuid);
    }
}
