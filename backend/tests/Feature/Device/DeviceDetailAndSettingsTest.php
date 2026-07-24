<?php

namespace Tests\Feature\Device;

use App\Models\Device;
use App\Models\DeviceOwnerType;
use App\Models\DeviceType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * 管理画面の端末詳細モーダル(docs/23-usecases-devices.md「端末管理画面(UI)」)向けの
 * 端末詳細取得・設定変更API。
 */
class DeviceDetailAndSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        return $admin;
    }

    public function test_admin_can_view_device_detail(): void
    {
        $admin = $this->admin();
        $device = Device::factory()->create([
            'owner_type' => DeviceOwnerType::ORGANIZATION_SHARED,
            'device_type' => DeviceType::ANDROID,
            'location_name' => '本社1階受付',
        ]);

        $response = $this->actingAs($admin)->getJson("/api/devices/{$device->id}");

        $response->assertSuccessful();
        $response->assertJsonPath('id', $device->id);
        $response->assertJsonPath('location_name', '本社1階受付');
    }

    public function test_non_admin_cannot_view_device_detail(): void
    {
        $employee = User::factory()->create();
        $device = Device::factory()->create(['owner_type' => DeviceOwnerType::ORGANIZATION_SHARED]);

        $this->actingAs($employee)->getJson("/api/devices/{$device->id}")->assertForbidden();
    }

    public function test_admin_can_update_device_settings(): void
    {
        $admin = $this->admin();
        $device = Device::factory()->create([
            'owner_type' => DeviceOwnerType::ORGANIZATION_SHARED,
            'device_type' => DeviceType::ANDROID,
            'location_name' => '本社1階受付',
        ]);

        $response = $this->actingAs($admin)->patchJson("/api/devices/{$device->id}", [
            'name' => $device->name,
            'location_name' => '本社2階会議室',
            'require_location' => true,
        ]);

        $response->assertSuccessful();
        $response->assertJsonPath('location_name', '本社2階会議室');
        $response->assertJsonPath('require_location', true);
        $this->assertSame('本社2階会議室', $device->fresh()->location_name);
    }

    public function test_updating_device_settings_records_a_domain_event(): void
    {
        $admin = $this->admin();
        $device = Device::factory()->create(['owner_type' => DeviceOwnerType::ORGANIZATION_SHARED]);

        $this->actingAs($admin)->patchJson("/api/devices/{$device->id}", [
            'name' => $device->name,
            'location_name' => '倉庫入口',
        ])->assertSuccessful();

        $this->assertTrue(
            EloquentStoredEvent::query()
                ->where('aggregate_uuid', $device->id)
                ->where('event_class', 'device.settings_updated')
                ->exists(),
        );
    }

    public function test_non_admin_cannot_update_device_settings(): void
    {
        $employee = User::factory()->create();
        $device = Device::factory()->create(['owner_type' => DeviceOwnerType::ORGANIZATION_SHARED]);

        $this->actingAs($employee)->patchJson("/api/devices/{$device->id}", [
            'name' => $device->name,
        ])->assertForbidden();
    }
}
