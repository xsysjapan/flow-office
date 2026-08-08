<?php

namespace Tests\Feature\UserManagement;

use App\Domain\AccessControl\Services\EffectiveAccessResolver;
use App\Domain\UserManagement\Aggregates\UserAggregate;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\UserManagementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class UserManagementAccessIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_management_and_access_control_seeders_are_idempotent(): void
    {
        $admin = User::factory()->create(['entra_user_id' => 'entra-admin']);
        $role = Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']);
        $admin->roles()->attach($role);

        $this->seed([UserManagementSeeder::class, AccessControlSeeder::class]);
        $this->seed([UserManagementSeeder::class, AccessControlSeeder::class]);

        $this->assertDatabaseHas('groups', ['code' => 'ALL_USERS']);
        $this->assertDatabaseHas('groups', ['code' => 'SYSTEM_ADMINISTRATORS']);
        $this->assertDatabaseHas('external_identities', ['user_id' => $admin->id, 'external_subject_id' => 'entra-admin']);
        $this->assertSame(3, DB::table('memberships')->where('user_id', $admin->id)->count());
        $this->assertSame(4, DB::table('group_feature_assignments')
            ->where('group_id', DB::table('groups')->where('code', 'ALL_USERS')->value('id'))
            ->count());
        $this->assertDatabaseHas('role_assignments', [
            'subject_type' => 'group',
            'subject_id' => DB::table('groups')->where('code', 'ALL_USERS')->value('id'),
            'role_id' => Role::query()->where('code', Role::EMPLOYEE)->value('id'),
            'status' => 'active',
        ]);
    }

    public function test_group_creation_flows_through_aggregate_event_and_projector(): void
    {
        $admin = User::factory()->create();
        $adminRole = Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者', 'status' => 'active']);
        $admin->roles()->attach($adminRole);
        $typeId = DB::table('group_types')->insertGetId(['code' => 'ORGANIZATION', 'name' => '組織', 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->actingAs($admin)->postJson('/api/admin/user-management/groups', [
            'group_type_id' => $typeId,
            'name' => '開発部',
            'code' => 'DEVELOPMENT',
        ])->assertCreated();

        $this->assertDatabaseHas('groups', ['id' => $response->json('id'), 'code' => 'DEVELOPMENT']);
        $this->assertDatabaseHas('stored_events', ['aggregate_uuid' => $response->json('id'), 'event_class' => 'group.created']);
    }

    public function test_group_features_and_permissions_are_combined_and_user_suspension_removes_feature(): void
    {
        $user = User::factory()->create();
        $typeId = DB::table('group_types')->insertGetId(['code' => 'ORGANIZATION', 'name' => '組織', 'created_at' => now(), 'updated_at' => now()]);
        $groupId = (string) Str::uuid();
        DB::table('groups')->insert(['id' => $groupId, 'group_type_id' => $typeId, 'name' => '開発部', 'code' => 'DEV', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('memberships')->insert(['user_id' => $user->id, 'group_id' => $groupId, 'created_at' => now(), 'updated_at' => now()]);
        $featureId = DB::table('features')->insertGetId(['code' => 'attendance', 'name' => '勤怠', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('group_feature_assignments')->insert(['group_id' => $groupId, 'feature_id' => $featureId, 'created_at' => now(), 'updated_at' => now()]);
        $permissionId = DB::table('permissions')->insertGetId(['code' => 'attendance.read', 'resource' => 'attendance', 'action' => 'read', 'created_at' => now(), 'updated_at' => now()]);
        $role = Role::query()->create(['code' => 'attendance_reader', 'name' => '勤怠閲覧', 'status' => 'active']);
        DB::table('permission_role')->insert(['permission_id' => $permissionId, 'role_id' => $role->id]);
        DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'subject_type' => 'group', 'subject_id' => $groupId, 'role_id' => $role->id, 'scope_type' => 'global', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($user)->getJson('/api/access/me')->assertOk()
            ->assertJsonPath('features.0', 'attendance')->assertJsonPath('permissions.0', 'attendance.read');

        DB::table('user_feature_suspensions')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'feature_id' => $featureId, 'reason' => '一時停止', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user)->getJson('/api/access/me')->assertOk()->assertJsonCount(0, 'features');
    }

    public function test_direct_user_role_assignment_is_effective_only_during_valid_period(): void
    {
        $user = User::factory()->create();
        $permissionId = DB::table('permissions')->insertGetId(['code' => 'user.manage', 'resource' => 'user', 'action' => 'manage', 'created_at' => now(), 'updated_at' => now()]);
        $role = Role::query()->create(['code' => 'user_manager', 'name' => 'ユーザー管理', 'status' => 'active']);
        DB::table('permission_role')->insert(['permission_id' => $permissionId, 'role_id' => $role->id]);
        DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'subject_type' => 'user', 'subject_id' => $user->id, 'role_id' => $role->id, 'scope_type' => 'global', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addMinute(), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($user)->getJson('/api/access/me')->assertOk()->assertJsonPath('permissions.0', 'user.manage');
        DB::table('role_assignments')->update(['ends_at' => now()->subMinute()]);
        $this->actingAs($user)->getJson('/api/access/me')->assertOk()->assertJsonCount(0, 'permissions');
    }

    public function test_membership_change_set_is_scheduled_and_applied_atomically(): void
    {
        $admin = User::factory()->create();
        $role = Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者', 'status' => 'active']);
        $admin->roles()->attach($role);
        $user = User::factory()->create();
        $typeId = DB::table('group_types')->insertGetId(['code' => 'PROJECT', 'name' => 'プロジェクト', 'created_at' => now(), 'updated_at' => now()]);
        $groupId = (string) Str::uuid();
        DB::table('groups')->insert(['id' => $groupId, 'group_type_id' => $typeId, 'name' => 'P1', 'code' => 'P1', 'created_at' => now(), 'updated_at' => now()]);
        $created = $this->actingAs($admin)->postJson('/api/admin/user-management/membership-change-sets', ['user_id' => $user->id, 'effective_at' => now()->addDay()->toISOString(), 'source_type' => 'manual', 'items' => [['operation' => 'add', 'group_type_id' => $typeId, 'target_group_id' => $groupId, 'is_primary' => false]]])->assertCreated();
        $this->assertDatabaseHas('membership_change_sets', ['id' => $created->json('id'), 'status' => 'scheduled']);
        $this->actingAs($admin)->postJson('/api/admin/user-management/membership-change-sets/'.$created->json('id').'/apply')->assertOk();
        $this->assertDatabaseHas('memberships', ['user_id' => $user->id, 'group_id' => $groupId]);
        $this->assertDatabaseHas('membership_change_sets', ['id' => $created->json('id'), 'status' => 'applied']);
    }

    public function test_due_membership_change_is_applied_by_scheduler_command(): void
    {
        $admin = User::factory()->create();
        $role = Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者', 'status' => 'active']);
        $admin->roles()->attach($role);
        $user = User::factory()->create();
        $typeId = DB::table('group_types')->insertGetId(['code' => 'COMMITTEE', 'name' => '委員会', 'created_at' => now(), 'updated_at' => now()]);
        $groupId = (string) Str::uuid();
        DB::table('groups')->insert(['id' => $groupId, 'group_type_id' => $typeId, 'name' => '安全委員会', 'code' => 'SAFETY', 'created_at' => now(), 'updated_at' => now()]);
        $created = $this->actingAs($admin)->postJson('/api/admin/user-management/membership-change-sets', ['user_id' => $user->id, 'effective_at' => now()->subMinute()->toISOString(), 'source_type' => 'manual', 'items' => [['operation' => 'add', 'group_type_id' => $typeId, 'target_group_id' => $groupId, 'is_primary' => false]]])->assertCreated();

        Artisan::call('user-management:apply-membership-changes');

        $this->assertDatabaseHas('membership_change_sets', ['id' => $created->json('id'), 'status' => 'applied']);
        $this->assertDatabaseHas('memberships', ['user_id' => $user->id, 'group_id' => $groupId]);
    }

    public function test_maintenance_mutations_are_audited_and_projected(): void
    {
        $admin = User::factory()->create();
        $adminRole = Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者', 'status' => 'active']);
        $admin->roles()->attach($adminRole);
        $user = User::factory()->create();
        $typeId = DB::table('group_types')->insertGetId(['code' => 'PROJECT', 'name' => 'プロジェクト', 'created_at' => now(), 'updated_at' => now()]);
        $groupId = (string) Str::uuid();
        DB::table('groups')->insert(['id' => $groupId, 'group_type_id' => $typeId, 'name' => 'P1', 'code' => 'P1', 'created_at' => now(), 'updated_at' => now()]);
        $featureId = DB::table('features')->insertGetId(['code' => 'workflow', 'name' => '申請', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($admin)->postJson('/api/admin/user-management/group-types', ['code' => 'CUSTOM_TEAM', 'name' => '任意チーム', 'membership_limit_type' => 'unlimited'])->assertCreated();
        $this->actingAs($admin)->postJson('/api/admin/access-control/roles', ['code' => 'custom_manager', 'name' => '任意管理者'])->assertCreated();
        $this->actingAs($admin)->patchJson("/api/admin/user-management/groups/{$groupId}", ['status' => 'inactive'])->assertOk();
        $this->actingAs($admin)->patchJson("/api/admin/user-management/groups/{$groupId}", ['status' => 'active'])->assertOk();

        $this->actingAs($admin)->postJson('/api/admin/user-management/memberships', ['user_id' => $user->id, 'group_id' => $groupId, 'membership_kind' => 'member'])->assertCreated();
        $this->actingAs($admin)->deleteJson("/api/admin/user-management/users/{$user->id}/groups/{$groupId}")->assertOk();
        $this->assertDatabaseMissing('memberships', ['user_id' => $user->id, 'group_id' => $groupId]);

        $this->actingAs($admin)->postJson('/api/admin/access-control/feature-suspensions', ['user_id' => $user->id, 'feature_id' => $featureId, 'reason' => '休職'])->assertCreated();
        $suspensionId = DB::table('user_feature_suspensions')->value('id');
        $this->actingAs($admin)->deleteJson("/api/admin/access-control/feature-suspensions/{$suspensionId}")->assertOk();
        $this->assertDatabaseMissing('user_feature_suspensions', ['id' => $suspensionId]);

        $this->actingAs($admin)->postJson("/api/admin/user-management/users/{$user->id}/external-identities", ['provider' => 'EXTERNAL_HR', 'external_subject_id' => 'HR-001'])->assertCreated();
        $identityId = DB::table('external_identities')->value('id');
        $this->actingAs($admin)->deleteJson("/api/admin/user-management/external-identities/{$identityId}")->assertOk();
        $this->assertDatabaseHas('external_identities', ['id' => $identityId, 'status' => 'unlinked']);

        foreach (['group_type.created', 'role.created', 'group.updated', 'membership.removed', 'user.feature_suspended', 'user.feature_suspension_removed', 'external_identity.linked', 'external_identity.unlinked'] as $event) {
            $this->assertDatabaseHas('stored_events', ['event_class' => $event]);
        }

        $this->actingAs($admin)->putJson('/api/admin/system-settings', ['default_timezone' => 'Asia/Tokyo'])->assertOk();
        $this->assertDatabaseHas('stored_events', ['event_class' => 'system_settings.updated']);
    }

    public function test_legacy_admin_role_does_not_bypass_explicit_feature_and_permission(): void
    {
        $admin = User::factory()->create();
        $role = Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者', 'status' => 'active']);
        $admin->roles()->attach($role);
        DB::table('features')->insert(['code' => 'administration', 'name' => '管理', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permissions')->insert(['code' => 'user.manage', 'resource' => 'user', 'action' => 'manage', 'created_at' => now(), 'updated_at' => now()]);
        $typeId = DB::table('group_types')->insertGetId(['code' => 'CUSTOM', 'name' => '任意', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($admin)->postJson('/api/admin/user-management/groups', ['group_type_id' => $typeId, 'name' => '拒否対象', 'code' => 'DENIED'])->assertForbidden();
    }

    public function test_primary_required_is_checked_for_immediate_and_scheduled_membership_changes(): void
    {
        $admin = User::factory()->create();
        $role = Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者', 'status' => 'active']);
        $admin->roles()->attach($role);
        $user = User::factory()->create();
        $typeId = DB::table('group_types')->insertGetId(['code' => 'EMPLOYMENT', 'name' => '雇用', 'status' => 'active', 'primary_membership_required' => true, 'max_primary_memberships' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $groupId = (string) Str::uuid();
        DB::table('groups')->insert(['id' => $groupId, 'group_type_id' => $typeId, 'name' => '正社員', 'code' => 'FULL_TIME', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($admin)->postJson('/api/admin/user-management/memberships', ['user_id' => $user->id, 'group_id' => $groupId, 'membership_kind' => 'member', 'is_primary' => false])->assertUnprocessable();
        $this->actingAs($admin)->postJson('/api/admin/user-management/membership-change-sets', ['user_id' => $user->id, 'effective_at' => now()->addDay()->toISOString(), 'source_type' => 'manual', 'items' => [['operation' => 'add', 'group_type_id' => $typeId, 'target_group_id' => $groupId, 'is_primary' => false]]])->assertUnprocessable();
    }

    public function test_external_identity_uses_a_dedicated_stream_and_can_be_relinked(): void
    {
        $admin = User::factory()->create();
        $role = Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者', 'status' => 'active']);
        $admin->roles()->attach($role);
        $user = User::factory()->create();
        UserAggregate::retrieve($user->id)->recordLogin(false, now()->toISOString())->persist();
        $payload = ['provider' => 'EXTERNAL_HR', 'external_subject_id' => 'HR-RELINK'];
        $this->actingAs($admin)->postJson("/api/admin/user-management/users/{$user->id}/external-identities", $payload)->assertCreated();
        $identityId = DB::table('external_identities')->where('external_subject_id', 'HR-RELINK')->value('id');
        $this->actingAs($admin)->deleteJson("/api/admin/user-management/external-identities/{$identityId}")->assertOk();
        $this->actingAs($admin)->postJson("/api/admin/user-management/users/{$user->id}/external-identities", $payload)->assertCreated();
        $this->assertDatabaseHas('stored_events', ['event_class' => 'external_identity.linked', 'aggregate_uuid' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'access-control:user-identity:'.$user->id)->toString()]);
    }

    public function test_last_system_administrator_cannot_lose_admin_role(): void
    {
        $admin = User::factory()->create();
        $role = Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者', 'status' => 'active']);
        $admin->roles()->attach($role);
        $this->actingAs($admin)->putJson("/api/users/{$admin->id}/roles", ['role_codes' => []])->assertUnprocessable();
    }

    public function test_last_membership_of_primary_required_type_cannot_be_removed(): void
    {
        $admin = User::factory()->create();
        $role = Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者', 'status' => 'active']);
        $admin->roles()->attach($role);
        $user = User::factory()->create();
        $this->seed([UserManagementSeeder::class, AccessControlSeeder::class]);
        $typeId = DB::table('group_types')->insertGetId(['code' => 'PRIMARY_REQUIRED', 'name' => '主所属必須', 'status' => 'active', 'primary_membership_required' => true, 'max_primary_memberships' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $groupId = (string) Str::uuid();
        DB::table('groups')->insert(['id' => $groupId, 'group_type_id' => $typeId, 'name' => '唯一所属', 'code' => 'ONLY_PRIMARY', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('memberships')->insert(['user_id' => $user->id, 'group_id' => $groupId, 'membership_kind' => 'primary', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($admin)->deleteJson("/api/admin/user-management/users/{$user->id}/groups/{$groupId}")->assertUnprocessable();
    }

    public function test_manual_user_creation_is_audited_and_adds_default_access(): void
    {
        $admin = User::factory()->create();
        $role = Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者', 'status' => 'active']);
        $admin->roles()->attach($role);
        $this->seed([UserManagementSeeder::class, AccessControlSeeder::class]);
        $response = $this->actingAs($admin)->postJson('/api/users', ['name' => '新規 利用者', 'email' => 'new-user@example.com', 'employee_number' => 'E-100']);
        $response->assertCreated();
        $userId = $response->json('id');
        $this->assertDatabaseHas('stored_events', ['aggregate_uuid' => $userId, 'event_class' => 'user.created_manually']);
        $allUsers = DB::table('groups')->where('code', 'ALL_USERS')->value('id');
        $this->assertDatabaseHas('memberships', ['user_id' => $userId, 'group_id' => $allUsers]);
        $this->assertDatabaseHas('role_user', ['user_id' => $userId, 'role_id' => Role::query()->where('code', Role::EMPLOYEE)->value('id')]);
    }

    public function test_external_hr_display_name_authority_blocks_local_name_update(): void
    {
        $admin = User::factory()->create();
        $role = Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者', 'status' => 'active']);
        $admin->roles()->attach($role);
        $target = User::factory()->create();
        $this->seed([UserManagementSeeder::class, AccessControlSeeder::class]);
        DB::table('field_authorities')->where('field_key', 'display_name')->update(['authority_type' => 'EXTERNAL_HR']);
        $this->actingAs($admin)->patchJson("/api/users/{$target->id}", ['name' => '変更不可'])->assertUnprocessable();
    }

    public function test_last_active_administrator_cannot_be_disabled(): void
    {
        $admin = User::factory()->create();
        $role = Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者', 'status' => 'active']);
        $admin->roles()->attach($role);
        $this->seed([UserManagementSeeder::class, AccessControlSeeder::class]);
        $this->actingAs($admin)->patchJson("/api/users/{$admin->id}", ['account_status' => 'disabled'])->assertUnprocessable();
    }

    public function test_company_setting_can_prohibit_self_privileged_role_assignment(): void
    {
        $admin = User::factory()->create();
        $role = Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者', 'status' => 'active']);
        $admin->roles()->attach($role);
        $this->seed([UserManagementSeeder::class, AccessControlSeeder::class]);
        SystemSetting::current()->update(['prohibit_self_privileged_role_assignment' => true]);
        $this->actingAs($admin)->postJson('/api/admin/access-control/role-assignments', ['subject_type' => 'user', 'subject_id' => $admin->id, 'role_id' => $role->id, 'scope_type' => 'global'])->assertUnprocessable();
    }

    public function test_group_scoped_permission_applies_to_descendant_members_only(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $outside = User::factory()->create();
        $typeId = DB::table('group_types')->insertGetId(['code' => 'ORG_SCOPE', 'name' => '組織', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $parent = (string) Str::uuid();
        $child = (string) Str::uuid();
        DB::table('groups')->insert([['id' => $parent, 'group_type_id' => $typeId, 'name' => '本社', 'code' => 'HQ_SCOPE', 'parent_group_id' => null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()], ['id' => $child, 'group_type_id' => $typeId, 'name' => '支社', 'code' => 'BRANCH_SCOPE', 'parent_group_id' => $parent, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('memberships')->insert(['user_id' => $target->id, 'group_id' => $child, 'membership_kind' => 'member', 'created_at' => now(), 'updated_at' => now()]);
        $permissionId = DB::table('permissions')->insertGetId(['code' => 'user.manage', 'resource' => 'user', 'action' => 'manage', 'created_at' => now(), 'updated_at' => now()]);
        $roleId = DB::table('roles')->insertGetId(['code' => 'BRANCH_HR', 'name' => '支社人事', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permission_role')->insert(['permission_id' => $permissionId, 'role_id' => $roleId]);
        DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'subject_type' => 'user', 'subject_id' => $actor->id, 'role_id' => $roleId, 'scope_type' => 'group', 'scope_group_id' => $parent, 'include_descendants' => true, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $resolver = app(EffectiveAccessResolver::class);
        $this->assertTrue($resolver->hasPermission($actor, 'user.manage', resourceUserId: $target->id));
        $this->assertFalse($resolver->hasPermission($actor, 'user.manage', resourceUserId: $outside->id));
    }

    public function test_system_group_type_allows_name_and_display_order_but_not_constraints(): void
    {
        $admin = User::factory()->create();
        $role = Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者', 'status' => 'active']);
        $admin->roles()->attach($role);
        $this->seed([UserManagementSeeder::class, AccessControlSeeder::class]);
        $typeId = DB::table('group_types')->where('code', 'ORGANIZATION')->value('id');
        $this->actingAs($admin)->patchJson("/api/admin/user-management/group-types/{$typeId}", ['name' => '部署', 'display_order' => 42])->assertOk();
        $this->assertDatabaseHas('group_types', ['id' => $typeId, 'name' => '部署', 'display_order' => 42]);
        $this->actingAs($admin)->patchJson("/api/admin/user-management/group-types/{$typeId}", ['membership_limit_type' => 'limited', 'max_memberships_per_user' => 1])->assertUnprocessable();
    }

    public function test_system_role_can_be_renamed_and_permissions_edited_without_removing_user_manage(): void
    {
        $admin = User::factory()->create();
        $role = Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者', 'status' => 'active']);
        $admin->roles()->attach($role);
        $this->seed([UserManagementSeeder::class, AccessControlSeeder::class]);
        $this->actingAs($admin)->patchJson("/api/admin/access-control/roles/{$role->id}", ['name' => 'システム管理者', 'description' => '全体管理'])->assertOk();
        $userManageId = DB::table('permissions')->where('code', 'user.manage')->value('id');
        $this->actingAs($admin)->putJson("/api/admin/access-control/roles/{$role->id}/permissions", ['permission_ids' => [$userManageId]])->assertOk();
        $this->actingAs($admin)->putJson("/api/admin/access-control/roles/{$role->id}/permissions", ['permission_ids' => []])->assertUnprocessable();
    }

    public function test_external_hr_import_creates_a_user_with_default_membership_and_role(): void
    {
        $admin = User::factory()->create();
        $role = Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者', 'status' => 'active']);
        $admin->roles()->attach($role);
        $this->seed([UserManagementSeeder::class, AccessControlSeeder::class]);
        DB::table('field_authorities')->whereIn('field_key', ['display_name', 'email'])->update(['authority_type' => 'EXTERNAL_HR', 'provider' => 'EXTERNAL_HR']);
        $userId = (string) Str::uuid();
        $this->actingAs($admin)->postJson('/api/admin/user-management/external-hr/import', ['rows' => [['user_id' => $userId, 'external_subject_id' => 'HR-NEW-001', 'changes' => ['display_name' => '外部 人事', 'email' => 'external-hr@example.com']]]])->assertCreated();
        $this->assertDatabaseHas('users', ['id' => $userId, 'name' => '外部 人事', 'source_type' => 'external_hr']);
        $this->assertDatabaseHas('memberships', ['user_id' => $userId, 'group_id' => DB::table('groups')->where('code', 'ALL_USERS')->value('id')]);
        $this->assertDatabaseHas('role_user', ['user_id' => $userId, 'role_id' => Role::query()->where('code', Role::EMPLOYEE)->value('id')]);
    }
}
