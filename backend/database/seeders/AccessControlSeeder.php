<?php

namespace Database\Seeders;

use App\Domain\AccessControl\AccessControlCatalog;
use App\Domain\AccessControl\Commands\AssignFeatureToGroup;
use App\Domain\AccessControl\Commands\ChangeRolePermissions;
use App\Domain\AccessControl\Commands\CreateRole;
use App\Domain\AccessControl\Commands\CreateRoleAssignment;
use App\Domain\AccessControl\Services\SyncAccessControlCatalog;
use App\Domain\EventSourcing\CommandBus;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class AccessControlSeeder extends Seeder
{
    public function run(): void
    {
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

        $bus = app(CommandBus::class);
        $roleNames = [
            Role::EMPLOYEE => '従業員', Role::BACKOFFICE_STAFF => 'バックオフィス担当',
            Role::ACCOUNTING_STAFF => '経理担当', Role::GENERAL_AFFAIRS_STAFF => '総務担当',
            Role::HR_STAFF => '人事担当', Role::ADMIN => 'システム管理者',
        ];
        foreach ($roleNames as $code => $name) {
            if (! DB::table('roles')->where('code', $code)->exists()) {
                $bus->dispatch(new CreateRole($code, $name, null, null, true));
            }
        }

        // 既存の行を上書きしない。ロールの権限は、まだ一度も設定されていない(イベントも行も無い)ものだけ設定する。
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
            $hasRows = DB::table('permission_role')->where('role_id', $role->id)->exists();
            $hasEvents = DB::table('stored_events')
                ->where('aggregate_uuid', Uuid::uuid5(Uuid::NAMESPACE_URL, 'role:'.$role->code)->toString())
                ->where('event_class', 'role.permissions_changed')
                ->exists();
            if ($hasRows || $hasEvents) {
                continue;
            }
            $bus->dispatch(new ChangeRolePermissions(
                (int) $role->id,
                DB::table('permissions')->whereIn('code', $codes)->pluck('id')->map(fn ($id) => (int) $id)->all(),
                null,
            ));
        }

        // 標準グループへの所属だけでFeatureとPermissionが有効になるよう、RoleAssignmentも
        // グループへ割り当てる。旧role_userはアクセス判定に使用しない。
        // 既にある割当は飛ばす。
        // CreateRoleAssignmentHandlerはgroup_feature_assignmentsをrole_featuresへ同期する(syncGroup)ため、
        // 下のFeature割当より先に発行する(この時点でrole_featuresは空・割当も空なので何も起きない)。
        foreach ([
            [$allUsersGroupId, Role::EMPLOYEE, 'self'],
            [$adminGroupId, Role::ADMIN, 'global'],
            [$hrGroupId, Role::HR_STAFF, 'global'],
            [$backofficeGroupId, Role::BACKOFFICE_STAFF, 'global'],
        ] as [$groupId, $roleCode, $scopeType]) {
            $roleId = (int) Role::query()->where('code', $roleCode)->value('id');
            $assignmentId = Uuid::uuid5(Uuid::NAMESPACE_URL, "standard-group-role-assignment:{$groupId}:{$roleCode}")->toString();
            if (DB::table('role_assignments')->where('id', $assignmentId)->exists()) {
                continue;
            }
            $bus->dispatch(new CreateRoleAssignment($assignmentId, 'group', $groupId, $roleId, $scopeType, null, false, null, null, null));
        }

        // 移行済み環境の現行設定を初期値とする。子Featureだけを選択している場合も、
        // 現行DBの割当をそのまま再現し、親Featureを暗黙に追加しない。
        // 既存の行を上書きしない: まだ一度も設定されていない(イベントも行も無い)グループだけ設定する。
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
            $hasRows = DB::table('group_feature_assignments')->where('group_id', $groupId)->exists();
            $hasEvents = DB::table('stored_events')
                ->where('aggregate_uuid', Uuid::uuid5(Uuid::NAMESPACE_URL, 'group-access:'.$groupId)->toString())
                ->exists();
            if ($hasRows || $hasEvents) {
                continue;
            }
            foreach ($featureCodes as $code) {
                $bus->dispatch(new AssignFeatureToGroup($groupId, (int) DB::table('features')->where('code', $code)->value('id'), null));
            }
        }
    }
}
