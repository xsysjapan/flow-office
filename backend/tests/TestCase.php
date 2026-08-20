<?php

namespace Tests;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * MS365(Entra ID)連携の設定は本来`system_settings`(初回オンボーディング)で管理者が
     * 設定するものだが、初回作成時のフォールバックとして`.env`(`services.azure.*`)を
     * 読む(`App\Models\SystemSetting::current()`参照)。開発者のローカル`.env`に
     * ローカル開発・E2Eテスト用のモック資格情報(mock-oidc向け)が設定されていても、
     * テストスイートはそれに影響されず常に「MS365未設定」から始まる必要があるため、
     * ここで明示的に上書きする(`phpunit.xml`の`<env>`だけでは、`php artisan test`経由の
     * 起動時にOS環境変数として既に`.env`の値が読み込まれてしまい上書きされないことがある)。
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.azure.mock_enabled' => false,
            'services.azure.client_id' => null,
            'services.azure.client_secret' => null,
            'services.azure.tenant' => 'common',
            'access_control.allow_unconfigured_catalog' => true,
        ]);
    }

    public function actingAs(UserContract $user, $guard = null)
    {
        // In production every user belongs to ALL_USERS, whose employee RoleAssignment
        // grants self-service attendance permissions. Legacy feature tests use a bare
        // User factory without running the product seeders, so reproduce that baseline
        // assignment when the compatibility mode is enabled.
        if (config('access_control.allow_unconfigured_catalog', false)
            && $user instanceof User
            && Schema::hasTable('roles')
            && Schema::hasTable('role_assignments')) {
            $employeeRole = Role::query()->firstOrCreate(
                ['code' => Role::EMPLOYEE],
                ['name' => 'Employee', 'is_system' => true, 'status' => 'active'],
            );
            $this->assignRole($user, $employeeRole);

            // Existing domain tests allow any employee selected as an approver to
            // execute that assigned task. Model that dynamic approval-task gateway;
            // controllers still verify that the concrete request is assigned to them.
            DB::table('permissions')->updateOrInsert(['code' => 'approval.execute'], [
                'resource' => 'approval',
                'action' => 'execute',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $approvalPermissionId = DB::table('permissions')->where('code', 'approval.execute')->value('id');
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $employeeRole->id,
                'permission_id' => $approvalPermissionId,
            ]);
            DB::table('permission_scope_types')->insertOrIgnore([
                'permission_id' => $approvalPermissionId,
                'scope_type' => 'approval_task',
            ]);
            RoleAssignment::query()->firstOrCreate([
                'subject_type' => 'user',
                'subject_id' => $user->id,
                'role_id' => $employeeRole->id,
                'scope_type' => 'approval_task',
                'scope_group_id' => null,
            ], [
                'include_descendants' => false,
                'status' => 'active',
                'assigned_by' => $user->id,
            ]);
        }

        return parent::actingAs($user, $guard);
    }

    /**
     * Grant a test user an access-control role through the current assignment model.
     */
    protected function assignRole(User $user, Role $role): RoleAssignment
    {
        $role->update(['status' => 'active']);

        $rolePermissions = [
            Role::EMPLOYEE => ['attendance.read', 'attendance.update'],
            Role::BACKOFFICE_STAFF => ['approval.execute', 'backoffice_task.execute'],
            Role::ACCOUNTING_STAFF => ['approval.execute', 'backoffice_task.execute', 'expense.export', 'expense_preset.manage', 'expense_category.manage'],
            Role::GENERAL_AFFAIRS_STAFF => ['approval.execute', 'backoffice_task.execute'],
            Role::HR_STAFF => ['user.view', 'user.create', 'user.update', 'user.disable', 'user.manage', 'group.view', 'group.create', 'group.update', 'group.disable', 'group.membership.update', 'group.change.schedule', 'group_type.view', 'external_hr.import', 'attendance.read', 'attendance.update', 'attendance.export', 'attendance.manage', 'leave.manage', 'approval.execute', 'backoffice_task.execute'],
            Role::ADMIN => ['user.view', 'user.create', 'user.update', 'user.disable', 'user.manage', 'group.view', 'group.create', 'group.update', 'group.disable', 'group.membership.update', 'group.change.schedule', 'group_type.view', 'group_type.create', 'group_type.update', 'role.view', 'role.create', 'role.update', 'role.assign', 'feature.view', 'feature.assign', 'external_identity.view', 'external_identity.manage', 'field_authority.view', 'field_authority.update', 'authentication_key.view', 'authentication_key.manage', 'external_hr.import', 'backoffice_task.execute', 'attendance.export', 'attendance.manage', 'leave.manage', 'expense.export', 'expense_preset.manage', 'request_type.manage', 'expense_category.manage', 'attendance_reminder_exclusion.manage', 'device.manage', 'audit_log.view', 'audit_log.export', 'attendance.read', 'attendance.update', 'approval.execute', 'approval.route.change', 'system_settings.read', 'system_settings.update', 'admin_command.view', 'admin_command.execute'],
        ];
        foreach ($rolePermissions[$role->code] ?? [] as $code) {
            [$resource, $action] = explode('.', $code, 2);
            DB::table('permissions')->updateOrInsert(['code' => $code], [
                'resource' => $resource,
                'action' => $action,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $permissionId = DB::table('permissions')->where('code', $code)->value('id');
            DB::table('permission_role')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permissionId]);
            DB::table('permission_scope_types')->insertOrIgnore([
                'permission_id' => $permissionId,
                'scope_type' => $role->code === Role::EMPLOYEE ? 'self' : 'global',
            ]);
        }

        $featureCodes = match ($role->code) {
            Role::ADMIN => ['attendance.entry', 'attendance.timesheet', 'workflow.requests', 'paid_leave.requests', 'backoffice.expenses', 'backoffice.tasks', 'administration.users', 'administration.settings'],
            Role::HR_STAFF => ['attendance.entry', 'attendance.timesheet', 'workflow.requests', 'paid_leave.requests', 'backoffice.tasks', 'administration.users'],
            Role::ACCOUNTING_STAFF => ['backoffice.expenses', 'backoffice.tasks'],
            Role::BACKOFFICE_STAFF, Role::GENERAL_AFFAIRS_STAFF => ['backoffice.tasks'],
            default => [],
        };
        if ($featureCodes !== []) {
            $parentCodes = ['attendance.entry' => 'attendance', 'attendance.timesheet' => 'attendance', 'workflow.requests' => 'workflow', 'paid_leave.requests' => 'paid_leave', 'backoffice.expenses' => 'backoffice', 'backoffice.tasks' => 'backoffice', 'administration.users' => 'administration', 'administration.settings' => 'administration'];
            $groupTypeId = DB::table('group_types')->where('code', 'TEST_ACCESS')->value('id') ?? DB::table('group_types')->insertGetId(['code' => 'TEST_ACCESS', 'name' => 'Test access', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $groupId = (string) Str::uuid();
            DB::table('groups')->insert(['id' => $groupId, 'group_type_id' => $groupTypeId, 'name' => 'Test access', 'code' => 'TEST_ACCESS_'.Str::upper(Str::random(12)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('memberships')->insert(['user_id' => $user->id, 'group_id' => $groupId, 'membership_kind' => 'member', 'created_at' => now(), 'updated_at' => now()]);
            foreach ($featureCodes as $code) {
                $parentCode = $parentCodes[$code];
                DB::table('features')->updateOrInsert(['code' => $parentCode], ['name' => $parentCode, 'status' => 'active', 'updated_at' => now(), 'created_at' => now()]);
                $parentId = DB::table('features')->where('code', $parentCode)->value('id');
                DB::table('features')->updateOrInsert(['code' => $code], ['name' => $code, 'parent_feature_id' => $parentId, 'status' => 'active', 'updated_at' => now(), 'created_at' => now()]);
                DB::table('group_feature_assignments')->insertOrIgnore(['group_id' => $groupId, 'feature_id' => DB::table('features')->where('code', $code)->value('id'), 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        return RoleAssignment::query()->firstOrCreate([
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => $role->code === Role::EMPLOYEE ? 'self' : 'global',
            'scope_group_id' => null,
        ], [
            'include_descendants' => false,
            'status' => 'active',
            'assigned_by' => $user->id,
        ]);
    }
}
