<?php

namespace Tests\Feature\Device;

use App\Models\AttendanceDay;
use App\Models\AttendancePunch;
use App\Models\AuthenticationKey;
use App\Models\AuthenticationKeyType;
use App\Models\Device;
use App\Models\DeviceOwnerType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * UC-A020/UC-D002: 共有Android打刻リーダーからのICカード打刻(docs/23-usecases-devices.md、
 * docs/24-usecases-authentication-keys.md)。
 */
class DevicePunchTest extends TestCase
{
    use RefreshDatabase;

    private function issueKey(User $user, string $rawValue): AuthenticationKey
    {
        $this->actingAs($user)->postJson('/api/users/me/authentication-keys', [
            'key_type' => AuthenticationKeyType::NFC_UID,
            'display_name' => 'カード',
            'raw_key_value' => $rawValue,
        ])->assertCreated();

        return AuthenticationKey::query()->where('user_id', $user->id)->firstOrFail();
    }

    public function test_shared_device_punch_resolves_user_from_authentication_key(): void
    {
        $employee = User::factory()->create();
        $this->issueKey($employee, 'NFC-UID-001');

        $device = Device::factory()->create([
            'owner_type' => DeviceOwnerType::ORGANIZATION_SHARED,
            'default_work_location_type' => 'office',
        ]);
        Sanctum::actingAs($device, ['recorder:punch']);

        $response = $this->postJson('/api/device-punches', [
            'work_date' => '2026-07-18',
            'punch_type' => 'clock_in',
            'punched_at' => '2026-07-18T09:00:00+09:00',
            'authentication_key_value' => 'NFC-UID-001',
        ]);

        $response->assertSuccessful();
        $punch = AttendancePunch::query()->firstOrFail();
        $this->assertSame($employee->id, $punch->user_id);
        $this->assertSame($device->id, $punch->device_id);
        $this->assertNotNull($punch->authentication_key_id);
    }

    public function test_shared_device_punch_with_unknown_key_is_rejected_and_not_recorded(): void
    {
        $device = Device::factory()->create(['owner_type' => DeviceOwnerType::ORGANIZATION_SHARED]);
        Sanctum::actingAs($device, ['recorder:punch']);

        $this->postJson('/api/device-punches', [
            'work_date' => '2026-07-18',
            'punch_type' => 'clock_in',
            'punched_at' => '2026-07-18T09:00:00+09:00',
            'authentication_key_value' => 'UNKNOWN-UID',
        ])->assertStatus(422);

        $this->assertSame(0, AttendancePunch::query()->count());
    }

    public function test_disabled_authentication_key_cannot_be_used_to_punch(): void
    {
        $employee = User::factory()->create();
        $key = $this->issueKey($employee, 'NFC-UID-002');
        $this->actingAs($employee)->postJson("/api/authentication-keys/{$key->id}/disable")->assertSuccessful();

        $device = Device::factory()->create(['owner_type' => DeviceOwnerType::ORGANIZATION_SHARED]);
        Sanctum::actingAs($device, ['recorder:punch']);

        $this->postJson('/api/device-punches', [
            'work_date' => '2026-07-18',
            'punch_type' => 'clock_in',
            'punched_at' => '2026-07-18T09:00:00+09:00',
            'authentication_key_value' => 'NFC-UID-002',
        ])->assertStatus(422);
    }

    public function test_reconciled_punches_apply_the_devices_default_work_location_type(): void
    {
        $employee = User::factory()->create();
        $this->issueKey($employee, 'NFC-UID-003');

        $device = Device::factory()->create([
            'owner_type' => DeviceOwnerType::ORGANIZATION_SHARED,
            'default_work_location_type' => 'client_site',
        ]);
        Sanctum::actingAs($device, ['recorder:punch']);

        $this->postJson('/api/device-punches', [
            'work_date' => '2026-07-18',
            'punch_type' => 'clock_in',
            'punched_at' => '2026-07-18T09:00:00+09:00',
            'authentication_key_value' => 'NFC-UID-003',
        ])->assertSuccessful();
        $this->postJson('/api/device-punches', [
            'work_date' => '2026-07-18',
            'punch_type' => 'clock_out',
            'punched_at' => '2026-07-18T18:00:00+09:00',
            'authentication_key_value' => 'NFC-UID-003',
        ])->assertSuccessful();

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-07-18')->firstOrFail();
        $this->assertSame('client_site', $day->work_location_type);
    }

    public function test_idempotency_key_prevents_duplicate_punches_on_retry(): void
    {
        $employee = User::factory()->create();
        $this->issueKey($employee, 'NFC-UID-004');

        $device = Device::factory()->create(['owner_type' => DeviceOwnerType::ORGANIZATION_SHARED]);
        Sanctum::actingAs($device, ['recorder:punch']);

        $payload = [
            'work_date' => '2026-07-18',
            'punch_type' => 'clock_in',
            'punched_at' => '2026-07-18T09:00:00+09:00',
            'authentication_key_value' => 'NFC-UID-004',
            'idempotency_key' => 'device-local-uuid-1',
        ];

        $this->postJson('/api/device-punches', $payload)->assertSuccessful();
        // オフラインキューからの再送を想定した同一冪等性キーでの再実行。
        $this->postJson('/api/device-punches', $payload)->assertSuccessful();

        $this->assertSame(1, AttendancePunch::query()->count());
    }

    public function test_a_colliding_idempotency_key_from_a_different_user_is_rejected(): void
    {
        $employeeA = User::factory()->create();
        $this->issueKey($employeeA, 'NFC-UID-005');
        $employeeB = User::factory()->create();
        $this->issueKey($employeeB, 'NFC-UID-006');

        $device = Device::factory()->create(['owner_type' => DeviceOwnerType::ORGANIZATION_SHARED]);
        Sanctum::actingAs($device, ['recorder:punch']);

        $this->postJson('/api/device-punches', [
            'work_date' => '2026-07-18',
            'punch_type' => 'clock_in',
            'punched_at' => '2026-07-18T09:00:00+09:00',
            'authentication_key_value' => 'NFC-UID-005',
            'idempotency_key' => 'colliding-key',
        ])->assertSuccessful();

        // 端末側の不具合等で異なる利用者の打刻に同じ冪等性キーが使われた場合、
        // 最初の利用者の打刻を誤って返さず、エラーとして拒否する。
        $response = $this->postJson('/api/device-punches', [
            'work_date' => '2026-07-18',
            'punch_type' => 'clock_in',
            'punched_at' => '2026-07-18T09:05:00+09:00',
            'authentication_key_value' => 'NFC-UID-006',
            'idempotency_key' => 'colliding-key',
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, AttendancePunch::query()->count());
        $this->assertSame($employeeA->id, AttendancePunch::query()->firstOrFail()->user_id);
    }

    public function test_personal_device_punches_as_its_own_owner_without_an_authentication_key(): void
    {
        $employee = User::factory()->create();
        $device = Device::factory()->create([
            'owner_type' => DeviceOwnerType::PERSONAL,
            'owner_user_id' => $employee->id,
        ]);
        Sanctum::actingAs($device, ['punch:self']);

        $this->postJson('/api/device-punches', [
            'work_date' => '2026-07-18',
            'punch_type' => 'clock_in',
            'punched_at' => '2026-07-18T09:00:00+09:00',
        ])->assertSuccessful();

        $punch = AttendancePunch::query()->firstOrFail();
        $this->assertSame($employee->id, $punch->user_id);
        $this->assertNull($punch->authentication_key_id);
    }
}
