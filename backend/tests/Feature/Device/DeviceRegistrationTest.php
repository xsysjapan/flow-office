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
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
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

        // 管理者の認証済みトークンだけを根拠に、一時ペアリングトークン(claim token)を発行する。
        $pairing = $this->actingAs($admin)->postJson("/api/devices/{$deviceId}/pairing");
        $pairing->assertSuccessful();
        $claimToken = $pairing->json('claim_token');
        $this->assertNotEmpty($claimToken);
        // QRコードはclaim_urlに?claim_token=を付与した単純なURLとしてエンコードする
        // (docs/23-usecases-devices.md UC-D002)。JSONではなくURL自体で完結させるため、
        // このURLはサーバー側で確定させたものが返る。
        $pairing->assertJsonPath('claim_url', route('devices.pairing.claim'));

        // actingAs()で設定したセッション認証(webガード)がSanctumガードのフォールバックとして
        // 残り続け、以降のBearerトークンでの認証を上書きしてしまうため、テスト内で明示的に
        // クリアする(実運用では端末アプリにセッションクッキーは存在しないため発生しない)。
        $this->app['auth']->forgetGuards();

        // 端末アプリがQRコード経由で受け取ったclaim tokenを提示し、業務用の本トークンに
        // 交換する(claim tokenはdevice:claim-pairingのみのabilityを持つ)。
        $claim = $this->withToken($claimToken)->postJson('/api/devices/pairing/claim');

        $claim->assertSuccessful();
        $this->assertNotEmpty($claim->json('token'));
        $this->assertNotSame($claimToken, $claim->json('token'));
        $claim->assertJsonPath('api_base_url', preg_replace('#/devices/pairing/claim$#', '', route('devices.pairing.claim')));

        $device->refresh();
        $this->assertSame(DeviceStatus::ACTIVE, $device->status);
        $this->assertNotNull($device->paired_at);
    }

    public function test_claim_token_cannot_be_reused_after_claiming(): void
    {
        $admin = $this->admin();
        $response = $this->actingAs($admin)->postJson('/api/devices', [
            'name' => '倉庫入口',
            'device_type' => DeviceType::ANDROID,
            'role_types' => [DeviceRoleType::ATTENDANCE_READER],
        ]);
        $deviceId = $response->json('id');

        $pairing = $this->actingAs($admin)->postJson("/api/devices/{$deviceId}/pairing");
        $claimToken = $pairing->json('claim_token');

        $this->app['auth']->forgetGuards();
        $this->withToken($claimToken)->postJson('/api/devices/pairing/claim')->assertSuccessful();

        // 一度使ったclaim tokenは使い捨てのため、再度の交換は失敗する
        // (Sanctumトークン自体が削除済みのため401)。ガードは一度解決したユーザーを
        // インスタンス内にキャッシュするため、再度明示的にクリアする。
        $this->app['auth']->forgetGuards();
        $this->withToken($claimToken)->postJson('/api/devices/pairing/claim')->assertUnauthorized();
    }

    public function test_a_device_token_cannot_claim_pairing(): void
    {
        $device = Device::factory()->create(['owner_type' => DeviceOwnerType::ORGANIZATION_SHARED]);
        Sanctum::actingAs($device, ['recorder:punch']);

        // device:claim-pairing ability を持たない通常の端末トークンでは呼び出せない。
        $this->postJson('/api/devices/pairing/claim')->assertForbidden();
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

    public function test_a_pending_claim_token_cannot_be_used_after_the_device_is_revoked(): void
    {
        $admin = $this->admin();
        $response = $this->actingAs($admin)->postJson('/api/devices', [
            'name' => '紛失した端末',
            'device_type' => DeviceType::ANDROID,
            'role_types' => [DeviceRoleType::ATTENDANCE_READER],
        ]);
        $deviceId = $response->json('id');

        $pairing = $this->actingAs($admin)->postJson("/api/devices/{$deviceId}/pairing");
        $claimToken = $pairing->json('claim_token');

        // claim token発行後、端末を紛失したものとして失効させる。
        $this->actingAs($admin)->postJson("/api/devices/{$deviceId}/revoke", ['reason' => '紛失'])->assertSuccessful();

        // 失効時にこの端末のSanctumトークン(claim token含む)がすべて削除されるため401。
        $this->app['auth']->forgetGuards();
        $this->withToken($claimToken)->postJson('/api/devices/pairing/claim')->assertUnauthorized();

        $device = Device::query()->findOrFail($deviceId);
        $this->assertSame(DeviceStatus::REVOKED, $device->status);
    }

    public function test_a_pending_claim_token_cannot_be_used_after_the_device_is_disabled(): void
    {
        $admin = $this->admin();
        $response = $this->actingAs($admin)->postJson('/api/devices', [
            'name' => '一時停止端末',
            'device_type' => DeviceType::ANDROID,
            'role_types' => [DeviceRoleType::ATTENDANCE_READER],
        ]);
        $deviceId = $response->json('id');

        $pairing = $this->actingAs($admin)->postJson("/api/devices/{$deviceId}/pairing");
        $claimToken = $pairing->json('claim_token');

        $this->actingAs($admin)->postJson("/api/devices/{$deviceId}/disable")->assertSuccessful();

        $this->app['auth']->forgetGuards();
        $this->withToken($claimToken)->postJson('/api/devices/pairing/claim')->assertUnauthorized();
    }

    public function test_admin_can_reissue_pairing_for_an_already_paired_device(): void
    {
        $admin = $this->admin();
        $response = $this->actingAs($admin)->postJson('/api/devices', [
            'name' => '本社2階受付',
            'device_type' => DeviceType::ANDROID,
            'role_types' => [DeviceRoleType::ATTENDANCE_READER],
        ]);
        $deviceId = $response->json('id');

        $firstPairing = $this->actingAs($admin)->postJson("/api/devices/{$deviceId}/pairing");
        $firstClaimToken = $firstPairing->json('claim_token');
        $this->app['auth']->forgetGuards();
        $this->withToken($firstClaimToken)->postJson('/api/devices/pairing/claim')->assertSuccessful();

        $device = Device::query()->findOrFail($deviceId);
        $this->assertSame(DeviceStatus::ACTIVE, $device->status);

        // Androidアプリを削除した等の理由で本トークンを失った場合でも、activeのまま
        // 再度ペアリング用トークンを発行し直せる。
        $this->app['auth']->forgetGuards();
        $reissue = $this->actingAs($admin)->postJson("/api/devices/{$deviceId}/pairing");
        $reissue->assertSuccessful();
        $secondClaimToken = $reissue->json('claim_token');
        $this->assertNotEmpty($secondClaimToken);
        $this->assertNotSame($firstClaimToken, $secondClaimToken);

        $device->refresh();
        $this->assertSame(DeviceStatus::PENDING_PAIRING, $device->status);
        $this->assertTrue(
            EloquentStoredEvent::query()
                ->where('aggregate_uuid', $device->aggregate_uuid)
                ->where('event_class', 'device.pairing_claim_issued')
                ->exists(),
        );

        $this->app['auth']->forgetGuards();
        $this->withToken($secondClaimToken)->postJson('/api/devices/pairing/claim')->assertSuccessful();

        $device->refresh();
        $this->assertSame(DeviceStatus::ACTIVE, $device->status);
    }

    public function test_disabled_or_revoked_device_cannot_reissue_pairing(): void
    {
        $admin = $this->admin();
        $device = Device::factory()->create([
            'owner_type' => DeviceOwnerType::ORGANIZATION_SHARED,
            'status' => DeviceStatus::DISABLED,
            'disabled_at' => now(),
        ]);

        $this->actingAs($admin)->postJson("/api/devices/{$device->id}/pairing")->assertUnprocessable();
    }

    public function test_admin_can_update_device_roles(): void
    {
        $admin = $this->admin();
        $device = Device::factory()->create(['owner_type' => DeviceOwnerType::ORGANIZATION_SHARED]);
        $device->roles()->create(['role_type' => DeviceRoleType::ATTENDANCE_READER]);

        $response = $this->actingAs($admin)->patchJson("/api/devices/{$device->id}/roles", [
            'role_types' => [DeviceRoleType::AUTHENTICATION_DEVICE, DeviceRoleType::ACCESS_CONTROL],
        ]);

        $response->assertSuccessful();
        $this->assertEqualsCanonicalizing(
            [DeviceRoleType::AUTHENTICATION_DEVICE, DeviceRoleType::ACCESS_CONTROL],
            $response->json('roles'),
        );

        $device->refresh()->load('roles');
        $this->assertFalse($device->hasRole(DeviceRoleType::ATTENDANCE_READER));
        $this->assertTrue($device->hasRole(DeviceRoleType::AUTHENTICATION_DEVICE));
        $this->assertTrue($device->hasRole(DeviceRoleType::ACCESS_CONTROL));
        $this->assertTrue(
            EloquentStoredEvent::query()
                ->where('aggregate_uuid', $device->aggregate_uuid)
                ->where('event_class', 'device.role_assigned')
                ->exists(),
        );
    }

    public function test_device_roles_cannot_be_emptied(): void
    {
        $admin = $this->admin();
        $device = Device::factory()->create(['owner_type' => DeviceOwnerType::ORGANIZATION_SHARED]);

        $this->actingAs($admin)->patchJson("/api/devices/{$device->id}/roles", [
            'role_types' => [],
        ])->assertUnprocessable();
    }

    public function test_admin_can_delete_a_disabled_or_revoked_device(): void
    {
        $admin = $this->admin();
        $device = Device::factory()->create([
            'owner_type' => DeviceOwnerType::ORGANIZATION_SHARED,
            'status' => DeviceStatus::DISABLED,
            'disabled_at' => now(),
        ]);

        $this->actingAs($admin)->deleteJson("/api/devices/{$device->id}")->assertNoContent();

        $this->assertSoftDeleted('devices', ['id' => $device->id]);
        $this->assertTrue(
            EloquentStoredEvent::query()
                ->where('aggregate_uuid', $device->aggregate_uuid)
                ->where('event_class', 'device.deleted')
                ->exists(),
        );
    }

    public function test_active_or_pending_device_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $device = Device::factory()->create([
            'owner_type' => DeviceOwnerType::ORGANIZATION_SHARED,
            'status' => DeviceStatus::PENDING_PAIRING,
        ]);

        $this->actingAs($admin)->deleteJson("/api/devices/{$device->id}")->assertUnprocessable();

        $this->assertDatabaseHas('devices', ['id' => $device->id, 'deleted_at' => null]);
    }

    public function test_deleted_devices_are_hidden_from_the_default_list_but_visible_with_trashed(): void
    {
        $admin = $this->admin();
        $device = Device::factory()->create([
            'owner_type' => DeviceOwnerType::ORGANIZATION_SHARED,
            'status' => DeviceStatus::REVOKED,
        ]);
        $this->actingAs($admin)->deleteJson("/api/devices/{$device->id}")->assertNoContent();

        $default = $this->actingAs($admin)->getJson('/api/devices');
        $default->assertSuccessful();
        $this->assertNotContains($device->id, array_column($default->json('data'), 'id'));

        $withTrashed = $this->actingAs($admin)->getJson('/api/devices?with_trashed=1');
        $withTrashed->assertSuccessful();
        $this->assertContains($device->id, array_column($withTrashed->json('data'), 'id'));
    }

    public function test_device_list_is_paginated(): void
    {
        $admin = $this->admin();
        Device::factory()->count(25)->create(['owner_type' => DeviceOwnerType::ORGANIZATION_SHARED]);

        $response = $this->actingAs($admin)->getJson('/api/devices');

        $response->assertSuccessful();
        $this->assertCount(20, $response->json('data'));
        $this->assertSame(2, $response->json('meta.last_page'));
    }
}
