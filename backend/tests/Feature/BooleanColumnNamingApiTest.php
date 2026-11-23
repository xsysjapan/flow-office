<?php

namespace Tests\Feature;

use App\Domain\AccessControl\Services\EffectiveAccessResolver;
use App\Models\AttendancePunch;
use App\Models\AuthenticationKey;
use App\Models\AuthenticationKeyType;
use App\Models\Device;
use App\Models\DeviceOwnerType;
use App\Models\DeviceRoleType;
use App\Models\DeviceType;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * 真偽値の列・API キーの改名(変更セット 20261123-boolean-column-naming)。
 *
 * - 管理画面向けの入力 API は旧名を 422(prohibited)で拒否する(論点10)。
 * - 端末アプリ向けの API(打刻・ペアリング・心拍)は互換期間として旧名・新名の両方を受け、両方を返す(論点9)。
 * - 権限の説明(EffectiveAccessResolver)と監査イベントのキーが新しい名前になる。
 */
class BooleanColumnNamingApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        return $admin;
    }

    private function grantAssetManage(User $user): void
    {
        DB::table('permissions')->updateOrInsert(
            ['code' => 'asset.manage'],
            ['resource' => 'asset', 'action' => 'manage', 'created_at' => now(), 'updated_at' => now()],
        );
        $permissionId = DB::table('permissions')->where('code', 'asset.manage')->value('id');
        DB::table('permission_scope_types')->insertOrIgnore(['permission_id' => $permissionId, 'scope_type' => 'global']);
        $role = Role::query()->create(['code' => 'ASSET_MANAGER_'.Str::upper(Str::random(12)), 'name' => 'Asset Manager (test)']);
        DB::table('permission_role')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permissionId]);
        RoleAssignment::query()->create([
            'subject_type' => 'user', 'subject_id' => $user->id, 'role_id' => $role->id, 'scope_type' => 'global',
            'scope_group_id' => null, 'includes_descendants' => false, 'status' => 'active', 'assigned_by' => $user->id,
        ]);
    }

    // --- 管理画面向け: 旧名は 422 (論点10) ---

    public function test_device_admin_api_rejects_old_setting_names(): void
    {
        $admin = $this->admin();
        $register = ['name' => '受付', 'device_type' => DeviceType::ANDROID, 'role_types' => [DeviceRoleType::ATTENDANCE_READER]];

        foreach (['allow_offline', 'require_location', 'auto_detect_punch_type'] as $old) {
            $this->actingAs($admin)->postJson('/api/admin/devices', $register + [$old => true])
                ->assertUnprocessable()->assertJsonValidationErrors([$old]);
        }

        $device = Device::factory()->create(['owner_type' => DeviceOwnerType::ORGANIZATION_SHARED]);
        foreach (['allow_offline', 'require_location', 'auto_detect_punch_type'] as $old) {
            $this->actingAs($admin)->patchJson("/api/admin/devices/{$device->id}", ['name' => $device->name, $old => true])
                ->assertUnprocessable()->assertJsonValidationErrors([$old]);
        }

        // 新名は通り、管理画面向けの応答は新名だけを返す。
        $response = $this->actingAs($admin)->patchJson("/api/admin/devices/{$device->id}", [
            'name' => $device->name,
            'allows_offline' => false,
            'requires_location' => true,
            'punch_type_auto_detection_enabled' => true,
        ])->assertSuccessful();
        $response->assertJsonPath('allows_offline', false);
        $response->assertJsonPath('requires_location', true);
        $response->assertJsonPath('punch_type_auto_detection_enabled', true);
        foreach (['allow_offline', 'require_location', 'auto_detect_punch_type'] as $old) {
            $this->assertArrayNotHasKey($old, $response->json());
        }
    }

    public function test_company_calendar_api_rejects_the_old_override_name(): void
    {
        $admin = $this->admin();
        $body = ['name' => '本社', 'fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31'];

        $this->actingAs($admin)->postJson('/api/company-calendars', $body + ['allow_daily_holiday_override' => false])
            ->assertUnprocessable()->assertJsonValidationErrors(['allow_daily_holiday_override']);

        $id = $this->actingAs($admin)->postJson('/api/company-calendars', $body + ['allows_daily_holiday_override' => false])
            ->assertSuccessful()->assertJsonPath('allows_daily_holiday_override', false)->json('id');

        $this->actingAs($admin)->putJson("/api/company-calendars/{$id}", ['name' => '本社', 'allow_daily_holiday_override' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['allow_daily_holiday_override']);
    }

    public function test_group_type_api_rejects_the_old_primary_membership_name(): void
    {
        $admin = $this->admin();
        $this->seed([\Database\Seeders\UserManagementSeeder::class, \Database\Seeders\AccessControlSeeder::class]);

        $this->actingAs($admin)->postJson('/api/admin/user-management/group-types', [
            'code' => 'OLD_NAME', 'name' => '旧名', 'membership_limit_type' => 'unlimited', 'primary_membership_required' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['primary_membership_required']);

        $this->actingAs($admin)->postJson('/api/admin/user-management/group-types', [
            'code' => 'NEW_NAME', 'name' => '新名', 'membership_limit_type' => 'unlimited',
            'requires_primary_membership' => true, 'max_primary_memberships' => 1,
        ])->assertCreated();
        $this->assertTrue((bool) DB::table('group_types')->where('code', 'NEW_NAME')->value('requires_primary_membership'));

        $typeId = DB::table('group_types')->where('code', 'NEW_NAME')->value('id');
        $this->actingAs($admin)->patchJson("/api/admin/user-management/group-types/{$typeId}", ['primary_membership_required' => false])
            ->assertUnprocessable()->assertJsonValidationErrors(['primary_membership_required']);
    }

    public function test_role_assignment_api_rejects_the_old_include_descendants_name(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $this->seed([\Database\Seeders\UserManagementSeeder::class, \Database\Seeders\AccessControlSeeder::class]);
        $role = Role::query()->create(['code' => 'reader', 'name' => '閲覧', 'status' => 'active']);
        DB::table('permission_role')->insert(['role_id' => $role->id, 'permission_id' => DB::table('permissions')->where('code', 'user.view')->value('id')]);
        $body = ['subject_type' => 'user', 'subject_id' => $user->id, 'role_id' => $role->id, 'scope_type' => 'global'];

        $this->actingAs($admin)->postJson('/api/admin/access-control/role-assignments', $body + ['include_descendants' => false])
            ->assertUnprocessable()->assertJsonValidationErrors(['include_descendants']);

        $id = $this->actingAs($admin)->postJson('/api/admin/access-control/role-assignments', $body + ['includes_descendants' => false])
            ->assertCreated()->json('id');

        $this->actingAs($admin)->patchJson("/api/admin/access-control/role-assignments/{$id}", ['include_descendants' => false])
            ->assertUnprocessable()->assertJsonValidationErrors(['include_descendants']);
    }

    public function test_external_integration_api_rejects_the_old_enabled_name_and_audit_uses_publishing_enabled(): void
    {
        $admin = $this->admin();
        $body = ['provider' => 'freee', 'name' => '会計', 'auth_type' => 'api_key', 'api_key' => 'secret-key'];

        $this->actingAs($admin)->postJson('/api/admin/external-integration-connections', $body + ['enabled' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['enabled']);

        $response = $this->actingAs($admin)->postJson('/api/admin/external-integration-connections', $body + ['publishing_enabled' => true])
            ->assertCreated();
        $response->assertJsonPath('publishing_enabled', true);
        $this->assertArrayNotHasKey('enabled', $response->json());

        $id = $response->json('id');
        $this->actingAs($admin)->patchJson("/api/admin/external-integration-connections/{$id}", ['enabled' => false])
            ->assertUnprocessable()->assertJsonValidationErrors(['enabled']);
        $this->actingAs($admin)->patchJson("/api/admin/external-integration-connections/{$id}", ['publishing_enabled' => false])
            ->assertSuccessful();

        // 監査イベント(auditPayload)のキーも新しい名前。
        $created = EloquentStoredEvent::query()->where('event_class', 'external_integration_connection.created')->firstOrFail();
        $this->assertTrue($created->event_properties['after']['publishing_enabled']);
        $this->assertArrayNotHasKey('enabled', $created->event_properties['after']);
        $updated = EloquentStoredEvent::query()->where('event_class', 'external_integration_connection.updated')->firstOrFail();
        $this->assertTrue($updated->event_properties['before']['publishing_enabled']);
        $this->assertFalse($updated->event_properties['after']['publishing_enabled']);
        $this->assertArrayNotHasKey('enabled', $updated->event_properties['before']);
        $this->assertArrayNotHasKey('enabled', $updated->event_properties['after']);
    }

    public function test_asset_number_rule_api_rejects_the_old_enabled_name_and_audit_uses_is_active(): void
    {
        $user = User::factory()->create();
        $this->grantAssetManage($user);

        $this->actingAs($user)->putJson('/api/asset-number-rules/default', ['prefix' => 'AST', 'enabled' => false])
            ->assertUnprocessable()->assertJsonValidationErrors(['enabled']);

        $response = $this->actingAs($user)->putJson('/api/asset-number-rules/default', ['prefix' => 'AST', 'is_active' => false])
            ->assertSuccessful();
        $response->assertJsonPath('is_active', false);
        $this->assertArrayNotHasKey('enabled', $response->json());

        $event = EloquentStoredEvent::query()->where('event_class', 'asset_number_rule.configured')->firstOrFail();
        $this->assertFalse($event->event_properties['isActive']);
        $this->assertArrayNotHasKey('enabled', $event->event_properties);
    }

    public function test_system_settings_api_rejects_the_old_prohibit_name_and_audit_uses_prohibits(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->putJson('/api/admin/system-settings', ['default_timezone' => 'Asia/Tokyo', 'prohibit_self_privileged_role_assignment' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['prohibit_self_privileged_role_assignment']);

        $response = $this->actingAs($admin)->putJson('/api/admin/system-settings', ['default_timezone' => 'Asia/Tokyo', 'prohibits_self_privileged_role_assignment' => true])
            ->assertSuccessful();
        $response->assertJsonPath('prohibits_self_privileged_role_assignment', true);
        $this->assertArrayNotHasKey('prohibit_self_privileged_role_assignment', $response->json());

        $event = EloquentStoredEvent::query()->where('event_class', 'system_settings.updated')->latest('id')->firstOrFail();
        $this->assertFalse($event->event_properties['before']['prohibits_self_privileged_role_assignment']);
        $this->assertTrue($event->event_properties['after']['prohibits_self_privileged_role_assignment']);
        $this->assertArrayNotHasKey('prohibit_self_privileged_role_assignment', $event->event_properties['after']);
    }

    // --- 端末アプリ向け: 旧名・新名の両方を受け、両方を返す (論点9) ---

    private function issueKey(User $user, string $rawValue): void
    {
        $this->actingAs($user)->postJson('/api/users/me/authentication-keys', [
            'key_type' => AuthenticationKeyType::NFC_UID,
            'display_name' => 'カード',
            'raw_key_value' => $rawValue,
        ])->assertCreated();
        $this->assertNotNull(AuthenticationKey::query()->where('user_id', $user->id)->first());
    }

    /** @param  array<string, mixed>  $extra */
    private function devicePunch(array $extra)
    {
        return $this->postJson('/api/device-punches', $extra + [
            'work_date' => '2026-07-18',
            'punch_type' => 'clock_in',
            'punched_at' => '2026-07-18T09:00:00+09:00',
            'authentication_key_value' => 'NFC-UID-COMPAT',
        ]);
    }

    private function sharedDeviceWithKey(): void
    {
        $employee = User::factory()->create();
        $this->issueKey($employee, 'NFC-UID-COMPAT');
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(Device::factory()->create(['owner_type' => DeviceOwnerType::ORGANIZATION_SHARED]), ['recorder:punch']);
    }

    public function test_device_punch_accepts_the_new_is_offline_name_and_returns_both_names(): void
    {
        $this->sharedDeviceWithKey();

        $response = $this->devicePunch(['is_offline' => true])->assertSuccessful();

        $response->assertJsonPath('data.is_offline', true);
        $response->assertJsonPath('data.offline', true);
        $this->assertTrue((bool) AttendancePunch::query()->firstOrFail()->is_offline);
    }

    public function test_device_punch_still_accepts_the_old_offline_name(): void
    {
        $this->sharedDeviceWithKey();

        $response = $this->devicePunch(['offline' => true])->assertSuccessful();

        $response->assertJsonPath('data.is_offline', true);
        $response->assertJsonPath('data.offline', true);
        $this->assertTrue((bool) AttendancePunch::query()->firstOrFail()->is_offline);
    }

    public function test_device_punch_defaults_to_online_and_accepts_both_names_when_they_agree(): void
    {
        $this->sharedDeviceWithKey();

        $this->devicePunch([])->assertSuccessful()->assertJsonPath('data.is_offline', false)->assertJsonPath('data.offline', false);
        $this->devicePunch(['is_offline' => true, 'offline' => true, 'punch_type' => 'clock_out', 'punched_at' => '2026-07-18T18:00:00+09:00'])
            ->assertSuccessful()->assertJsonPath('data.is_offline', true);
    }

    public function test_device_punch_rejects_conflicting_old_and_new_names(): void
    {
        $this->sharedDeviceWithKey();

        $this->devicePunch(['is_offline' => true, 'offline' => false])->assertUnprocessable();

        $this->assertSame(0, AttendancePunch::query()->count());
    }

    public function test_heartbeat_returns_device_settings_under_new_and_old_names(): void
    {
        $device = Device::factory()->create([
            'owner_type' => DeviceOwnerType::ORGANIZATION_SHARED,
            'allows_offline' => false,
            'requires_location' => true,
            'punch_type_auto_detection_enabled' => true,
        ]);
        Sanctum::actingAs($device, ['recorder:punch']);

        $response = $this->postJson('/api/devices/heartbeat')->assertSuccessful();

        $response->assertJsonPath('allows_offline', false);
        $response->assertJsonPath('requires_location', true);
        $response->assertJsonPath('punch_type_auto_detection_enabled', true);
        $response->assertJsonPath('allow_offline', false);
        $response->assertJsonPath('require_location', true);
        $response->assertJsonPath('auto_detect_punch_type', true);
    }

    public function test_claim_pairing_returns_device_settings_under_new_and_old_names(): void
    {
        $admin = $this->admin();
        $deviceId = $this->actingAs($admin)->postJson('/api/admin/devices', [
            'name' => '受付', 'device_type' => DeviceType::ANDROID, 'role_types' => [DeviceRoleType::ATTENDANCE_READER],
            'allows_offline' => false, 'requires_location' => true, 'punch_type_auto_detection_enabled' => true,
        ])->assertCreated()->json('id');
        $claimToken = $this->actingAs($admin)->postJson("/api/admin/devices/{$deviceId}/pairing")->json('claim_token');
        $this->app['auth']->forgetGuards();

        $response = $this->withToken($claimToken)->postJson('/api/devices/pairing/claim')->assertSuccessful();

        $response->assertJsonPath('device.allows_offline', false);
        $response->assertJsonPath('device.requires_location', true);
        $response->assertJsonPath('device.punch_type_auto_detection_enabled', true);
        $response->assertJsonPath('device.allow_offline', false);
        $response->assertJsonPath('device.require_location', true);
        $response->assertJsonPath('device.auto_detect_punch_type', true);
    }

    // --- 権限の説明の出力キー ---

    public function test_effective_access_explanation_uses_includes_descendants(): void
    {
        $user = User::factory()->create();
        $role = Role::query()->create(['code' => 'explain_role', 'name' => '説明用', 'status' => 'active']);
        RoleAssignment::query()->create([
            'subject_type' => 'user', 'subject_id' => $user->id, 'role_id' => $role->id, 'scope_type' => 'global',
            'scope_group_id' => null, 'includes_descendants' => true, 'status' => 'active', 'assigned_by' => $user->id,
        ]);

        $explain = app(EffectiveAccessResolver::class)->explain($user);

        $source = $explain['roles'][0]['sources'][0];
        $this->assertTrue($source['includes_descendants']);
        $this->assertArrayNotHasKey('include_descendants', $source);
    }
}
