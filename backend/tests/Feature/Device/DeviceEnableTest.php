<?php

namespace Tests\Feature\Device;

use App\Models\Device;
use App\Models\DeviceOwnerType;
use App\Models\DeviceRoleType;
use App\Models\DeviceStatus;
use App\Models\DeviceType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-D005: 停止(disabled)中の端末を有効化(pending_pairingへ復帰)し、UC-D002のペアリングを
 * やり直せるようにするための2段階復旧フロー(docs/23-usecases-devices.md参照)。
 */
class DeviceEnableTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        return $admin;
    }

    public function test_admin_can_enable_a_disabled_device(): void
    {
        $admin = $this->admin();
        $device = Device::factory()->create([
            'owner_type' => DeviceOwnerType::ORGANIZATION_SHARED,
            'status' => DeviceStatus::DISABLED,
            'disabled_at' => now(),
        ]);

        $response = $this->actingAs($admin)->postJson("/api/devices/{$device->id}/enable");

        $response->assertSuccessful();
        $device->refresh();
        $this->assertSame(DeviceStatus::PENDING_PAIRING, $device->status);
        $this->assertNull($device->disabled_at);
    }

    public function test_a_reenabled_device_can_be_repaired(): void
    {
        $admin = $this->admin();
        $device = Device::factory()->create([
            'owner_type' => DeviceOwnerType::ORGANIZATION_SHARED,
            'device_type' => DeviceType::ANDROID,
            'status' => DeviceStatus::DISABLED,
            'disabled_at' => now(),
        ]);
        $device->roles()->create(['role_type' => DeviceRoleType::ATTENDANCE_READER]);

        $this->actingAs($admin)->postJson("/api/devices/{$device->id}/enable")->assertSuccessful();

        $pairing = $this->actingAs($admin)->postJson("/api/devices/{$device->id}/pairing");
        $pairing->assertSuccessful();
        $this->assertNotEmpty($pairing->json('claim_token'));

        $device->refresh();
        $this->assertSame(DeviceStatus::PENDING_PAIRING, $device->status);
    }

    public function test_active_device_cannot_be_enabled(): void
    {
        $admin = $this->admin();
        $device = Device::factory()->create([
            'owner_type' => DeviceOwnerType::ORGANIZATION_SHARED,
            'status' => DeviceStatus::ACTIVE,
        ]);

        $this->actingAs($admin)->postJson("/api/devices/{$device->id}/enable")->assertUnprocessable();
    }

    public function test_pending_pairing_device_cannot_be_enabled(): void
    {
        $admin = $this->admin();
        $device = Device::factory()->create([
            'owner_type' => DeviceOwnerType::ORGANIZATION_SHARED,
            'status' => DeviceStatus::PENDING_PAIRING,
        ]);

        $this->actingAs($admin)->postJson("/api/devices/{$device->id}/enable")->assertUnprocessable();
    }

    public function test_revoked_device_cannot_be_enabled(): void
    {
        $admin = $this->admin();
        $device = Device::factory()->create([
            'owner_type' => DeviceOwnerType::ORGANIZATION_SHARED,
            'status' => DeviceStatus::REVOKED,
            'revoked_at' => now(),
        ]);

        $this->actingAs($admin)->postJson("/api/devices/{$device->id}/enable")->assertUnprocessable();

        $device->refresh();
        $this->assertSame(DeviceStatus::REVOKED, $device->status);
    }

    public function test_personal_device_owner_can_enable_their_own_disabled_device(): void
    {
        $owner = User::factory()->create();
        $device = Device::factory()->create([
            'owner_type' => DeviceOwnerType::PERSONAL,
            'owner_user_id' => $owner->id,
            'status' => DeviceStatus::DISABLED,
            'disabled_at' => now(),
        ]);

        $this->actingAs($owner)->postJson("/api/devices/{$device->id}/enable")->assertSuccessful();

        $device->refresh();
        $this->assertSame(DeviceStatus::PENDING_PAIRING, $device->status);
    }

    public function test_another_user_cannot_enable_someone_elses_personal_device(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $device = Device::factory()->create([
            'owner_type' => DeviceOwnerType::PERSONAL,
            'owner_user_id' => $owner->id,
            'status' => DeviceStatus::DISABLED,
            'disabled_at' => now(),
        ]);

        $this->actingAs($otherUser)->postJson("/api/devices/{$device->id}/enable")->assertForbidden();

        $device->refresh();
        $this->assertSame(DeviceStatus::DISABLED, $device->status);
    }
}
