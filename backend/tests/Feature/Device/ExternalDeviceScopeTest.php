<?php

namespace Tests\Feature\Device;

use App\Models\AttendancePunch;
use App\Models\AuthenticationKeyType;
use App\Models\Device;
use App\Models\DeviceOwnerType;
use App\Models\DeviceScopeType;
use App\Models\DeviceType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * UC-D004: 外部端末登録・スコープ付与・identity:resolve(docs/23-usecases-devices.md)。
 */
class ExternalDeviceScopeTest extends TestCase
{
    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        return $admin;
    }

    use RefreshDatabase;

    public function test_admin_can_grant_a_scope_to_an_external_device(): void
    {
        $admin = $this->admin();
        $device = Device::factory()->create([
            'owner_type' => DeviceOwnerType::ORGANIZATION_SHARED,
            'device_type' => DeviceType::EXTERNAL_SYSTEM,
        ]);

        $response = $this->actingAs($admin)->postJson("/api/devices/{$device->id}/scopes", [
            'scope' => DeviceScopeType::ATTENDANCE_CLOCK,
        ]);

        $response->assertSuccessful();
        $this->assertContains(DeviceScopeType::ATTENDANCE_CLOCK, $response->json('scopes'));
    }

    public function test_an_existing_token_gains_a_newly_granted_scope_without_repairing(): void
    {
        $admin = $this->admin();
        $device = Device::factory()->create(['owner_type' => DeviceOwnerType::ORGANIZATION_SHARED]);
        $token = $device->createToken('external', [DeviceScopeType::ATTENDANCE_READ_RESULT]);

        $this->actingAs($admin)->postJson("/api/devices/{$device->id}/scopes", [
            'scope' => DeviceScopeType::ATTENDANCE_CLOCK,
        ])->assertSuccessful();

        $this->assertTrue($token->accessToken->fresh()->can(DeviceScopeType::ATTENDANCE_CLOCK));
    }

    public function test_external_device_with_attendance_clock_scope_can_punch(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee)->postJson('/api/users/me/authentication-keys', [
            'key_type' => AuthenticationKeyType::EXTERNAL_SYSTEM_USER_ID,
            'display_name' => '他社勤怠システムID',
            'raw_key_value' => 'EXT-SYS-USER-42',
        ])->assertCreated();

        $device = Device::factory()->create([
            'owner_type' => DeviceOwnerType::ORGANIZATION_SHARED,
            'device_type' => DeviceType::EXTERNAL_SYSTEM,
        ]);
        Sanctum::actingAs($device, [DeviceScopeType::ATTENDANCE_CLOCK]);

        $this->postJson('/api/device-punches', [
            'work_date' => '2026-07-18',
            'punch_type' => 'clock_in',
            'punched_at' => '2026-07-18T09:00:00+09:00',
            'authentication_key_value' => 'EXT-SYS-USER-42',
        ])->assertSuccessful();

        $this->assertSame($employee->id, AttendancePunch::query()->firstOrFail()->user_id);
    }

    public function test_external_device_without_scope_cannot_punch(): void
    {
        $device = Device::factory()->create(['owner_type' => DeviceOwnerType::ORGANIZATION_SHARED]);
        // 何のability/scopeも持たない外部端末トークン。
        Sanctum::actingAs($device, [DeviceScopeType::ATTENDANCE_READ_RESULT]);

        $this->postJson('/api/device-punches', [
            'work_date' => '2026-07-18',
            'punch_type' => 'clock_in',
            'punched_at' => '2026-07-18T09:00:00+09:00',
            'authentication_key_value' => 'ANY',
        ])->assertForbidden();
    }

    public function test_identity_resolve_returns_the_user_without_recording_a_punch(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee)->postJson('/api/users/me/authentication-keys', [
            'key_type' => AuthenticationKeyType::EXTERNAL_SYSTEM_USER_ID,
            'display_name' => '入退室管理ID',
            'raw_key_value' => 'ACCESS-CTRL-99',
        ])->assertCreated();

        $device = Device::factory()->create(['owner_type' => DeviceOwnerType::ORGANIZATION_SHARED]);
        Sanctum::actingAs($device, [DeviceScopeType::IDENTITY_RESOLVE]);

        $response = $this->postJson('/api/devices/identity/resolve', [
            'authentication_key_value' => 'ACCESS-CTRL-99',
        ]);

        $response->assertSuccessful();
        $response->assertJsonPath('user_id', $employee->id);
        $this->assertSame(0, AttendancePunch::query()->count());
    }
}
