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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * UC-D001〜UC-D005: 端末管理(docs/23-usecases-devices.md)。
 */
class DeviceRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        return $admin;
    }

    public function test_admin_can_register_a_shared_device_and_pair_it(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson('/api/devices', [
            'name' => '本社1階受付',
            'device_type' => DeviceType::ANDROID,
            'role_types' => [DeviceRoleType::ATTENDANCE_READER],
            'location_name' => '本社1階受付カウンター',
            'default_work_location_type' => 'office',
        ]);

        $response->assertCreated();
        $deviceId = $response->json('id');

        $device = Device::query()->findOrFail($deviceId);
        $this->assertSame(DeviceStatus::PENDING_PAIRING, $device->status);
        $this->assertSame(DeviceOwnerType::ORGANIZATION_SHARED, $device->owner_type);

        $pairing = $this->actingAs($admin)->postJson("/api/devices/{$deviceId}/pairing");
        $pairing->assertSuccessful();
        $code = $pairing->json('pairing_code');
        $this->assertNotEmpty($code);

        // 一般ユーザーがコードを使ってトークンに交換する(端末アプリ自身がSanctumトークンを
        // まだ持たない時点の呼び出しのため、認証不要)。
        $exchange = $this->postJson('/api/devices/pairing/exchange', [
            'device_id' => $deviceId,
            'pairing_code' => $code,
        ]);

        $exchange->assertSuccessful();
        $this->assertNotEmpty($exchange->json('token'));

        $device->refresh();
        $this->assertSame(DeviceStatus::ACTIVE, $device->status);
        $this->assertNotNull($device->paired_at);
        $this->assertNull($device->pairing_code_hash);
    }

    public function test_expired_or_wrong_pairing_code_is_rejected(): void
    {
        $admin = $this->admin();
        $response = $this->actingAs($admin)->postJson('/api/devices', [
            'name' => '倉庫入口',
            'device_type' => DeviceType::ANDROID,
            'role_types' => [DeviceRoleType::ATTENDANCE_READER],
        ]);
        $deviceId = $response->json('id');

        $this->actingAs($admin)->postJson("/api/devices/{$deviceId}/pairing")->assertSuccessful();

        $this->postJson('/api/devices/pairing/exchange', [
            'device_id' => $deviceId,
            'pairing_code' => 'WRONGCODE',
        ])->assertStatus(422);
    }

    public function test_non_admin_cannot_register_a_shared_device(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/devices', [
            'name' => '不正端末',
            'device_type' => DeviceType::ANDROID,
            'role_types' => [DeviceRoleType::ATTENDANCE_READER],
        ])->assertForbidden();
    }

    public function test_employee_can_register_and_use_a_personal_device(): void
    {
        $employee = User::factory()->create();

        $response = $this->actingAs($employee)->postJson('/api/users/me/devices', [
            'name' => '自分のスマートフォン',
            'device_type' => DeviceType::ANDROID,
        ]);

        $response->assertCreated();
        $this->assertNotEmpty($response->json('token'));

        $device = Device::query()->findOrFail($response->json('device.id'));
        $this->assertSame(DeviceOwnerType::PERSONAL, $device->owner_type);
        $this->assertSame(DeviceStatus::ACTIVE, $device->status);
        $this->assertSame($employee->id, $device->owner_user_id);
    }

    public function test_a_device_token_cannot_call_general_apis(): void
    {
        $device = Device::factory()->create(['owner_type' => DeviceOwnerType::ORGANIZATION_SHARED]);
        Sanctum::actingAs($device, ['recorder:punch']);

        // recorder:punch専用トークンは、abilityでオプトインしていない一般APIを操作できない
        // (docs/23-usecases-devices.md UC-D002「重要な考慮事項」)。
        $this->getJson('/api/users')->assertForbidden();
    }

    public function test_admin_can_disable_and_revoke_a_device(): void
    {
        $admin = $this->admin();
        $device = Device::factory()->create(['owner_type' => DeviceOwnerType::ORGANIZATION_SHARED]);

        $this->actingAs($admin)->postJson("/api/devices/{$device->id}/disable")->assertSuccessful();
        $device->refresh();
        $this->assertSame(DeviceStatus::DISABLED, $device->status);
        $this->assertNotNull($device->disabled_at);

        $this->actingAs($admin)->postJson("/api/devices/{$device->id}/revoke", ['reason' => '紛失'])->assertSuccessful();
        $device->refresh();
        $this->assertSame(DeviceStatus::REVOKED, $device->status);
    }
}
