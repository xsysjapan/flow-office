<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class AccessControlSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $groupId = DB::table('groups')->where('code', 'ALL_USERS')->value('id');
        $adminGroupId = DB::table('groups')->where('code', 'SYSTEM_ADMINISTRATORS')->value('id');
        $backofficeGroupId = DB::table('groups')->where('code', 'BACKOFFICE_USERS')->value('id');
        if (! $groupId || ! $adminGroupId || ! $backofficeGroupId) {
            throw new \RuntimeException('UserManagementSeeder must run before AccessControlSeeder.');
        }

        $features = ['attendance' => '勤怠', 'workflow' => '申請', 'paid_leave' => '有給', 'backoffice' => 'バックオフィス', 'administration' => '管理'];
        foreach ($features as $code => $name) {
            DB::table('features')->updateOrInsert(['code' => $code], ['name' => $name, 'status' => 'active', 'updated_at' => $now, 'created_at' => $now]);
        }
        foreach (['attendance', 'workflow', 'paid_leave'] as $code) {
            DB::table('group_feature_assignments')->updateOrInsert(['group_id' => $groupId, 'feature_id' => DB::table('features')->where('code', $code)->value('id')], ['updated_at' => $now, 'created_at' => $now]);
        }
        DB::table('group_feature_assignments')->updateOrInsert(['group_id' => $adminGroupId, 'feature_id' => DB::table('features')->where('code', 'administration')->value('id')], ['updated_at' => $now, 'created_at' => $now]);
        DB::table('group_feature_assignments')->updateOrInsert(['group_id' => $backofficeGroupId, 'feature_id' => DB::table('features')->where('code', 'backoffice')->value('id')], ['updated_at' => $now, 'created_at' => $now]);
        DB::table('group_feature_assignments')->updateOrInsert(['group_id' => $backofficeGroupId, 'feature_id' => DB::table('features')->where('code', 'administration')->value('id')], ['updated_at' => $now, 'created_at' => $now]);

        $permissionCodes = ['attendance.read', 'attendance.update', 'approval.execute', 'user.manage', 'system_settings.read', 'system_settings.update'];
        foreach ($permissionCodes as $code) {
            [$resource, $action] = explode('.', $code, 2);
            DB::table('permissions')->updateOrInsert(['code' => $code], ['resource' => $resource, 'action' => $action, 'updated_at' => $now, 'created_at' => $now]);
        }
        $roleNames = [
            Role::EMPLOYEE => '従業員', Role::BACKOFFICE_STAFF => 'バックオフィス担当',
            Role::ACCOUNTING_STAFF => '経理担当', Role::GENERAL_AFFAIRS_STAFF => '総務担当',
            Role::HR_STAFF => '人事担当', Role::ADMIN => '管理者',
        ];
        foreach ($roleNames as $code => $name) {
            Role::query()->firstOrCreate(['code' => $code], ['name' => $name, 'is_system' => true, 'status' => 'active']);
        }
        $admin = Role::query()->where('code', Role::ADMIN)->first();
        if ($admin) {
            $admin->update(['is_system' => true, 'status' => 'active']);
            foreach ($permissionCodes as $code) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $admin->id, 'permission_id' => DB::table('permissions')->where('code', $code)->value('id')]);
            }
        }
        Role::query()->whereIn('code', Role::defaultCodes())->update(['is_system' => true, 'status' => 'active']);
        $rolePermissions = [
            Role::EMPLOYEE => ['attendance.read', 'attendance.update'],
            Role::BACKOFFICE_STAFF => ['approval.execute'],
            Role::ACCOUNTING_STAFF => ['approval.execute'],
            Role::GENERAL_AFFAIRS_STAFF => ['approval.execute'],
            Role::HR_STAFF => ['attendance.read', 'attendance.update', 'approval.execute', 'user.manage'],
            Role::ADMIN => $permissionCodes,
        ];
        foreach ($rolePermissions as $roleCode => $codes) {
            $role = Role::query()->where('code', $roleCode)->first();
            if (! $role) {
                continue;
            } foreach ($codes as $code) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => DB::table('permissions')->where('code', $code)->value('id')]);
            }
        }

        User::query()->with('roles')->each(function (User $user) use ($now): void {
            foreach ($user->roles as $role) {
                $assignmentId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'legacy-role-assignment:'.$user->id.':'.$role->id)->toString();
                DB::table('role_assignments')->updateOrInsert(['id' => $assignmentId], ['subject_type' => 'user', 'subject_id' => $user->id, 'role_id' => $role->id, 'scope_type' => 'global', 'status' => 'active', 'updated_at' => $now, 'created_at' => $now]);
            }
        });
    }
}
