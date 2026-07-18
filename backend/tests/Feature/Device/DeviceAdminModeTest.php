<?php

namespace Tests\Feature\Device;

use App\Models\Device;
use App\Models\DeviceRoleType;
use App\Models\DeviceScopeType;
use App\Models\DeviceType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-D006: Android端末を管理者モードにする(docs/23-usecases-devices.md)。
 */
class DeviceAdminModeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->firstOrCreate(['code' => Role::ADMIN], ['name' => '管理者']));

        return $admin;
    }

    /**
     * 管理者トークンでの端末登録・スコープ付与・ペアリングを経て、端末を業務用トークンで
     * 認証させた状態を作る。
     */
    private function activateDevice(User $admin): string
    {
        $registered = $this->actingAs($admin)->postJson('/api/devices', [
            'name' => 'エントランス端末',
            'device_type' => DeviceType::ANDROID,
            'role_types' => [DeviceRoleType::AUTHENTICATION_DEVICE],
        ]);
        $registered->assertCreated();
        $deviceId = $registered->json('id');

        $this->actingAs($admin)->postJson("/api/devices/{$deviceId}/scopes", [
            'scope' => DeviceScopeType::ADMIN_MODE,
        ])->assertSuccessful();

        $pairing = $this->actingAs($admin)->postJson("/api/devices/{$deviceId}/pairing");
        $claimToken = $pairing->json('claim_token');

        $this->app['auth']->forgetGuards();

        $claim = $this->withToken($claimToken)->postJson('/api/devices/pairing/claim');
        $claim->assertSuccessful();

        // 直前のclaim tokenでの認証結果をガードが保持し続け、以降のリクエストで新しい
        // 本トークンを渡しても上書きされてしまうため、明示的にクリアする(実運用では
        // 端末アプリにセッションクッキーは存在しないため発生しない。DeviceRegistrationTest参照)。
        $this->app['auth']->forgetGuards();

        return $claim->json('token');
    }

    public function test_bootstrap_eligibility_is_self_when_activating_admin_is_still_admin(): void
    {
        $admin = $this->admin();
        $token = $this->activateDevice($admin);

        $response = $this->withToken($token)->getJson('/api/devices/me/admin-bootstrap');

        $response->assertSuccessful();
        $this->assertSame('self', $response->json('mode'));
        $this->assertSame($admin->id, $response->json('admin_user.id'));
    }

    public function test_bootstrap_registers_admin_card_and_enters_admin_mode(): void
    {
        $admin = $this->admin();
        $token = $this->activateDevice($admin);

        $response = $this->withToken($token)->postJson('/api/devices/me/admin-bootstrap/authentication-keys', [
            'key_type' => 'nfc_uid',
            'display_name' => '管理者ICカード',
            'raw_key_value' => 'nfc-admin-001',
        ]);

        $response->assertCreated();
        $this->assertSame($admin->id, $response->json('admin_session.admin_user.id'));
        $this->assertSame('bootstrap', $response->json('admin_session.source'));
        $this->assertSame($admin->id, $response->json('authentication_key.user_id'));

        // ブートストラップ登録と同時に管理者モードに入っているため、社員一覧を取得できる。
        $users = $this->withToken($token)->getJson('/api/devices/me/admin/users');
        $users->assertSuccessful();
    }

    public function test_bootstrap_eligibility_is_select_when_no_admin_is_linked_to_activation(): void
    {
        $admin = $this->admin();
        $otherAdmin = $this->admin();
        $token = $this->activateDevice($admin);

        // アクティベーションを行った管理者が既に管理者ロールを外れているケースを再現する。
        Device::query()->update(['activated_by_user_id' => null]);

        $response = $this->withToken($token)->getJson('/api/devices/me/admin-bootstrap');

        $response->assertSuccessful();
        $this->assertSame('select', $response->json('mode'));
        $adminIds = collect($response->json('admin_users'))->pluck('id');
        $this->assertTrue($adminIds->contains($admin->id));
        $this->assertTrue($adminIds->contains($otherAdmin->id));
    }

    public function test_bootstrap_rejects_a_non_admin_target_user(): void
    {
        $admin = $this->admin();
        $employee = User::factory()->create();
        $token = $this->activateDevice($admin);

        Device::query()->update(['activated_by_user_id' => null]);

        $response = $this->withToken($token)->postJson('/api/devices/me/admin-bootstrap/authentication-keys', [
            'admin_user_id' => $employee->id,
            'key_type' => 'nfc_uid',
            'display_name' => '管理者ICカード',
            'raw_key_value' => 'nfc-admin-002',
        ]);

        $response->assertStatus(422);
    }

    public function test_tapping_a_registered_admin_card_starts_admin_mode_and_registers_employee_nfc(): void
    {
        $admin = $this->admin();
        $employee = User::factory()->create();
        $token = $this->activateDevice($admin);

        $this->withToken($token)->postJson('/api/devices/me/admin-bootstrap/authentication-keys', [
            'key_type' => 'nfc_uid',
            'display_name' => '管理者ICカード',
            'raw_key_value' => 'nfc-admin-003',
        ])->assertCreated();

        $this->withToken($token)->postJson('/api/devices/me/admin-sessions/current/end')->assertSuccessful();

        $start = $this->withToken($token)->postJson('/api/devices/me/admin-sessions', [
            'raw_key_value' => 'nfc-admin-003',
        ]);
        $start->assertCreated();
        $this->assertSame('nfc_tap', $start->json('admin_session.source'));

        $register = $this->withToken($token)->postJson("/api/devices/me/admin/users/{$employee->id}/authentication-keys", [
            'key_type' => 'nfc_uid',
            'display_name' => '社員証',
            'raw_key_value' => 'nfc-employee-001',
        ]);
        $register->assertCreated();
        $this->assertSame($employee->id, $register->json('user_id'));

        $keys = $this->withToken($token)->getJson("/api/devices/me/admin/users/{$employee->id}/authentication-keys");
        $keys->assertSuccessful();
        $this->assertCount(1, $keys->json());
    }

    public function test_admin_endpoints_require_an_active_admin_session(): void
    {
        $admin = $this->admin();
        $token = $this->activateDevice($admin);

        $response = $this->withToken($token)->getJson('/api/devices/me/admin/users');

        $response->assertStatus(403);
    }
}
