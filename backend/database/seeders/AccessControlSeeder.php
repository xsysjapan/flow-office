<?php

namespace Database\Seeders;

use App\Domain\AccessControl\AccessControlCatalog;
use App\Domain\AccessControl\Services\SyncAccessControlCatalog;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class AccessControlSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $allUsersGroupId = DB::table('groups')->where('code', 'ALL_USERS')->value('id');
        $adminGroupId = DB::table('groups')->where('code', 'SYSTEM_ADMINISTRATORS')->value('id');
        $hrGroupId = DB::table('groups')->where('code', 'HUMAN_RESOURCES_USERS')->value('id');
        $backofficeGroupId = DB::table('groups')->where('code', 'BACKOFFICE_USERS')->value('id');
        if (! $allUsersGroupId || ! $adminGroupId || ! $hrGroupId || ! $backofficeGroupId) {
            throw new \RuntimeException('UserManagementSeeder must run before AccessControlSeeder.');
        }

        // Feature・Permission定義自体の同期は、デプロイ時にも自動実行される
        // `access-control:sync-catalog`(SyncAccessControlCatalog)と同じ処理を使う。
        // 定義の唯一の情報源はAccessControlCatalog。
        (new SyncAccessControlCatalog)->sync();

        $roleNames = [
            Role::EMPLOYEE => '従業員', Role::BACKOFFICE_STAFF => 'バックオフィス担当',
            Role::ACCOUNTING_STAFF => '経理担当', Role::GENERAL_AFFAIRS_STAFF => '総務担当',
            Role::HR_STAFF => '人事担当', Role::ADMIN => 'システム管理者',
        ];
        foreach ($roleNames as $code => $name) {
            Role::query()->firstOrCreate(['code' => $code], ['name' => $name, 'is_system' => true, 'status' => 'active']);
        }

        $rolePermissions = [
            Role::EMPLOYEE => ['attendance.read', 'attendance.update'],
            Role::BACKOFFICE_STAFF => ['approval.execute', 'backoffice_task.execute', 'attendance.confirmation_revert'],
            Role::ACCOUNTING_STAFF => ['approval.execute', 'backoffice_task.execute', 'expense.export', 'expense_preset.manage', 'expense_category.manage'],
            Role::GENERAL_AFFAIRS_STAFF => ['approval.execute', 'backoffice_task.execute'],
            Role::HR_STAFF => ['user.view', 'user.create', 'user.update', 'user.disable', 'group.view', 'group.create', 'group.update', 'group.disable', 'group.membership.update', 'group.change.schedule', 'group_type.view', 'external_hr.import', 'attendance.read', 'attendance.update', 'attendance.export', 'attendance.manage', 'leave.manage', 'approval.execute', 'backoffice_task.execute', 'user.manage'],
            Role::ADMIN => array_keys(AccessControlCatalog::PERMISSIONS),
        ];
        foreach ($rolePermissions as $roleCode => $codes) {
            $role = Role::query()->where('code', $roleCode)->firstOrFail();
            DB::table('permission_role')->where('role_id', $role->id)->delete();
            foreach ($codes as $code) {
                DB::table('permission_role')->insert(['role_id' => $role->id, 'permission_id' => DB::table('permissions')->where('code', $code)->value('id')]);
            }
        }

        // 標準グループへの所属だけでFeatureとPermissionが有効になるよう、RoleAssignmentも
        // グループへ割り当てる。旧role_userはアクセス判定に使用しない。
        foreach ([
            [$allUsersGroupId, Role::EMPLOYEE, 'self'],
            [$adminGroupId, Role::ADMIN, 'global'],
            [$hrGroupId, Role::HR_STAFF, 'global'],
            [$backofficeGroupId, Role::BACKOFFICE_STAFF, 'global'],
        ] as [$groupId, $roleCode, $scopeType]) {
            $roleId = Role::query()->where('code', $roleCode)->value('id');
            $assignmentId = Uuid::uuid5(Uuid::NAMESPACE_URL, "standard-group-role-assignment:{$groupId}:{$roleCode}")->toString();
            DB::table('role_assignments')->updateOrInsert(
                ['id' => $assignmentId],
                [
                    'subject_type' => 'group',
                    'subject_id' => $groupId,
                    'role_id' => $roleId,
                    'scope_type' => $scopeType,
                    'status' => 'active',
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }

        // 移行済み環境の現行設定を初期値とする。子Featureだけを選択している場合も、
        // 現行DBの割当をそのまま再現し、親Featureを暗黙に追加しない。
        $initialFeatures = [
            $allUsersGroupId => [
                'attendance', 'attendance.clock', 'attendance.entry', 'attendance.timesheet',
                'workflow', 'workflow.requests', 'paid_leave', 'paid_leave.requests',
                'backoffice.expenses',
            ],
            $backofficeGroupId => ['backoffice.tasks'],
            $adminGroupId => ['administration', 'administration.users', 'administration.settings'],
            $hrGroupId => ['administration', 'administration.users'],
        ];
        foreach ($initialFeatures as $groupId => $featureCodes) {
            foreach ($featureCodes as $code) {
                DB::table('group_feature_assignments')->updateOrInsert(
                    ['group_id' => $groupId, 'feature_id' => DB::table('features')->where('code', $code)->value('id')],
                    ['updated_at' => $now, 'created_at' => $now],
                );
            }
        }
    }
}
