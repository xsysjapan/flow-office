<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class AccessControlSeeder extends Seeder
{
    /** @var array<string, array{name: string, scopes: list<string>}> */
    private const PERMISSIONS = [
        'user.view' => ['name' => 'ユーザー閲覧', 'scopes' => ['global', 'group', 'self']],
        'user.create' => ['name' => 'ユーザー作成', 'scopes' => ['global', 'group']],
        'user.update' => ['name' => 'ユーザー更新', 'scopes' => ['global', 'group', 'self']],
        'user.disable' => ['name' => 'ユーザー無効化', 'scopes' => ['global', 'group']],
        'group.view' => ['name' => 'グループ閲覧', 'scopes' => ['global', 'group']],
        'group.create' => ['name' => 'グループ作成', 'scopes' => ['global', 'group']],
        'group.update' => ['name' => 'グループ更新', 'scopes' => ['global', 'group']],
        'group.disable' => ['name' => 'グループ無効化', 'scopes' => ['global', 'group']],
        'group.membership.update' => ['name' => '所属更新', 'scopes' => ['global', 'group']],
        'group.change.schedule' => ['name' => '所属変更予約', 'scopes' => ['global', 'group']],
        'group_type.view' => ['name' => 'GroupType閲覧', 'scopes' => ['global']],
        'group_type.create' => ['name' => 'GroupType作成', 'scopes' => ['global']],
        'group_type.update' => ['name' => 'GroupType更新', 'scopes' => ['global']],
        'role.view' => ['name' => 'Role閲覧', 'scopes' => ['global', 'group']],
        'role.create' => ['name' => 'Role作成', 'scopes' => ['global']],
        'role.update' => ['name' => 'Role更新', 'scopes' => ['global']],
        'role.assign' => ['name' => 'Role割当', 'scopes' => ['global', 'group']],
        'feature.view' => ['name' => 'Feature閲覧', 'scopes' => ['global', 'group']],
        'feature.assign' => ['name' => 'Feature割当', 'scopes' => ['global', 'group']],
        'external_identity.view' => ['name' => '外部ID閲覧', 'scopes' => ['global']],
        'external_identity.manage' => ['name' => '外部ID管理', 'scopes' => ['global']],
        'field_authority.view' => ['name' => '項目管理元閲覧', 'scopes' => ['global']],
        'field_authority.update' => ['name' => '項目管理元更新', 'scopes' => ['global']],
        'authentication_key.view' => ['name' => '認証キー閲覧', 'scopes' => ['global']],
        'authentication_key.manage' => ['name' => '認証キー管理', 'scopes' => ['global']],
        'external_hr.import' => ['name' => '外部HR取込', 'scopes' => ['global']],
        'backoffice_task.execute' => ['name' => 'バックオフィスタスク処理', 'scopes' => ['global']],
        'attendance.export' => ['name' => '勤怠出力', 'scopes' => ['global', 'group']],
        'attendance.manage' => ['name' => '勤怠マスタ管理', 'scopes' => ['global']],
        'leave.manage' => ['name' => '休暇マスタ・残数管理', 'scopes' => ['global']],
        'expense.export' => ['name' => '経費出力', 'scopes' => ['global']],
        'expense_preset.manage' => ['name' => '共有経費プリセット管理', 'scopes' => ['global']],
        'request_type.manage' => ['name' => '申請種別管理', 'scopes' => ['global']],
        'expense_category.manage' => ['name' => '経費区分管理', 'scopes' => ['global']],
        'attendance_reminder_exclusion.manage' => ['name' => '勤怠未提出督促除外管理', 'scopes' => ['global']],
        'device.manage' => ['name' => '共有端末管理', 'scopes' => ['global']],
        'audit_log.view' => ['name' => '監査ログ閲覧', 'scopes' => ['global']],
        'audit_log.export' => ['name' => '監査ログ出力', 'scopes' => ['global']],
        'attendance.read' => ['name' => '勤怠閲覧', 'scopes' => ['global', 'group', 'self']],
        'attendance.update' => ['name' => '勤怠更新', 'scopes' => ['global', 'group', 'self']],
        'approval.execute' => ['name' => '承認実行', 'scopes' => ['global', 'approval_task']],
        'approval.route.change' => ['name' => '承認ルート変更', 'scopes' => ['global', 'group']],
        'system_settings.read' => ['name' => 'システム設定閲覧', 'scopes' => ['global']],
        'system_settings.update' => ['name' => 'システム設定更新', 'scopes' => ['global']],
        'admin_command.view' => ['name' => '運用コマンド閲覧', 'scopes' => ['global']],
        'admin_command.execute' => ['name' => '運用コマンド実行', 'scopes' => ['global']],
        // 旧APIとの移行互換。新しい画面・APIは上記の操作単位Permissionを使用する。
        'user.manage' => ['name' => 'ユーザー管理（互換）', 'scopes' => ['global', 'group']],
    ];

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

        $parents = [
            'attendance' => ['勤怠', 10],
            'workflow' => ['申請', 20],
            'paid_leave' => ['休暇', 30],
            'backoffice' => ['経費・バックオフィス', 40],
            'administration' => ['管理', 50],
        ];
        foreach ($parents as $code => [$name, $order]) {
            DB::table('features')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'parent_feature_id' => null, 'display_order' => $order, 'is_selectable' => false, 'status' => 'active', 'updated_at' => $now, 'created_at' => $now],
            );
        }
        $children = [
            'attendance.clock' => ['打刻', 'attendance', 11],
            'attendance.entry' => ['勤怠入力', 'attendance', 12],
            'attendance.timesheet' => ['勤務表・月次提出', 'attendance', 13],
            'workflow.requests' => ['申請', 'workflow', 21],
            'paid_leave.requests' => ['休暇申請', 'paid_leave', 31],
            'backoffice.expenses' => ['経費精算', 'backoffice', 41],
            'backoffice.tasks' => ['バックオフィスタスク', 'backoffice', 42],
            'administration.users' => ['ユーザー・グループ管理', 'administration', 51],
            'administration.settings' => ['システム設定', 'administration', 52],
        ];
        foreach ($children as $code => [$name, $parent, $order]) {
            DB::table('features')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'parent_feature_id' => DB::table('features')->where('code', $parent)->value('id'), 'display_order' => $order, 'is_selectable' => true, 'status' => 'active', 'updated_at' => $now, 'created_at' => $now],
            );
        }

        foreach (self::PERMISSIONS as $code => $definition) {
            [$resource, $action] = explode('.', $code, 2);
            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                ['resource' => $resource, 'action' => $action, 'description' => $definition['name'], 'updated_at' => $now, 'created_at' => $now],
            );
            $permissionId = DB::table('permissions')->where('code', $code)->value('id');
            DB::table('permission_scope_types')->where('permission_id', $permissionId)->delete();
            DB::table('permission_scope_types')->insert(array_map(
                fn (string $scope) => ['permission_id' => $permissionId, 'scope_type' => $scope],
                $definition['scopes'],
            ));
        }

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
            Role::BACKOFFICE_STAFF => ['approval.execute', 'backoffice_task.execute'],
            Role::ACCOUNTING_STAFF => ['approval.execute', 'backoffice_task.execute', 'expense.export', 'expense_preset.manage', 'expense_category.manage'],
            Role::GENERAL_AFFAIRS_STAFF => ['approval.execute', 'backoffice_task.execute'],
            Role::HR_STAFF => ['user.view', 'user.create', 'user.update', 'user.disable', 'group.view', 'group.create', 'group.update', 'group.disable', 'group.membership.update', 'group.change.schedule', 'group_type.view', 'external_hr.import', 'attendance.read', 'attendance.update', 'attendance.export', 'attendance.manage', 'leave.manage', 'approval.execute', 'backoffice_task.execute', 'user.manage'],
            Role::ADMIN => array_keys(self::PERMISSIONS),
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
