<?php

namespace Tests\Feature\Device;

use App\Domain\AuthenticationKey\Commands\IssueAuthenticationKey;
use App\Domain\AuthenticationKey\Services\AuthenticationKeyHasher;
use App\Domain\DeviceAdminSession\Commands\StartDeviceAdminSessionBootstrap;
use App\Domain\EventSourcing\CommandBus;
use App\Models\AuthenticationKey;
use App\Models\DeviceAdminSession;
use App\Models\DeviceRoleType;
use App\Models\DeviceScopeType;
use App\Models\DeviceType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AssertsSingleTopLevelCommand;
use Tests\TestCase;

/**
 * 端末の管理者の初回の登録(ブートストラップ)と認証キー
 * (docs/changesets/20261128-remaining-entrypoints/spec.md 論点3)。
 */
class DeviceAdminBootstrapReactorTest extends TestCase
{
    use AssertsSingleTopLevelCommand;
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->firstOrCreate(['code' => Role::ADMIN], ['name' => '管理者']));

        return $admin;
    }

    private function activateDevice(User $admin): string
    {
        $registered = $this->actingAs($admin)->postJson('/api/admin/devices', [
            'name' => 'エントランス端末',
            'device_type' => DeviceType::ANDROID,
            'role_types' => [DeviceRoleType::AUTHENTICATION_DEVICE],
        ]);
        $registered->assertCreated();
        $deviceId = $registered->json('id');

        $this->actingAs($admin)->postJson("/api/admin/devices/{$deviceId}/scopes", [
            'scope' => DeviceScopeType::ADMIN_MODE,
        ])->assertSuccessful();

        $pairing = $this->actingAs($admin)->postJson("/api/admin/devices/{$deviceId}/pairing");
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

    public function test_bootstrap_is_a_single_command_and_session_points_to_the_registered_key(): void
    {
        $admin = $this->admin();
        $token = $this->activateDevice($admin);
        $bus = $this->recordCommands();

        $response = $this->withToken($token)->postJson('/api/devices/me/admin-bootstrap/authentication-keys', [
            'key_type' => 'nfc_uid', 'display_name' => '管理者カード', 'raw_key_value' => 'nfc-boot-1',
        ]);

        $response->assertCreated();
        $this->assertSingleTopLevelCommand($bus, StartDeviceAdminSessionBootstrap::class);
        $keyId = $response->json('authentication_key.id');
        $this->assertSame($admin->id, AuthenticationKey::query()->findOrFail($keyId)->user_id);
        $this->assertSame($keyId, DeviceAdminSession::query()->latest('started_at')->firstOrFail()->authentication_key_id);
        // 生の値はイベントに入らず、ハッシュだけが入る
        $payloads = DB::table('stored_events')->where('event_class', 'like', 'device_admin_session.%')->pluck('event_properties')->implode('');
        $this->assertStringNotContainsString('nfc-boot-1', $payloads);
        $this->assertStringContainsString(app(AuthenticationKeyHasher::class)->hash('nfc-boot-1'), $payloads);
    }

    public function test_duplicate_of_another_active_key_rejects_everything_even_via_reactor(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create();
        app(CommandBus::class)->dispatch(new IssueAuthenticationKey($other->id, 'nfc_uid', '他人', 'nfc-dup', null, null, null, $other->id));
        $token = $this->activateDevice($admin);
        $sessions = DeviceAdminSession::query()->count();
        $events = DB::table('stored_events')->count();

        $this->withToken($token)->postJson('/api/devices/me/admin-bootstrap/authentication-keys', [
            'key_type' => 'nfc_uid', 'display_name' => '管理者カード', 'raw_key_value' => 'nfc-dup',
        ])->assertUnprocessable();

        $this->assertSame($sessions, DeviceAdminSession::query()->count());
        $this->assertSame($events, DB::table('stored_events')->count());
        $this->assertSame(1, AuthenticationKey::query()->count());
    }

    public function test_issue_via_reactor_is_idempotent_for_the_same_key_id(): void
    {
        $admin = $this->admin();
        $keyId = (string) Str::uuid();
        $hash = app(AuthenticationKeyHasher::class)->hash('nfc-idem');
        $issue = fn () => app(CommandBus::class)->dispatch(new IssueAuthenticationKey(
            $admin->id, 'nfc_uid', 'カード', null, null, null, null, $admin->id, $keyId, $hash, true,
        ));

        $issue();
        $issue();

        $this->assertSame(1, AuthenticationKey::query()->count());
        $this->assertSame(1, DB::table('stored_events')->where('aggregate_uuid', $keyId)->count());
    }
}
