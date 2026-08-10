<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** 開発・E2Eシナリオ専用。製品初期状態とは分離して一般利用者の導線を明示的に開放する。 */
class ScenarioAccessSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $groupId = DB::table('groups')->where('code', 'ALL_USERS')->value('id');
        foreach (['attendance', 'attendance.clock', 'attendance.entry', 'attendance.timesheet', 'workflow', 'workflow.requests', 'paid_leave', 'paid_leave.requests', 'backoffice', 'backoffice.expenses'] as $code) {
            DB::table('group_feature_assignments')->updateOrInsert(
                ['group_id' => $groupId, 'feature_id' => DB::table('features')->where('code', $code)->value('id')],
                ['updated_at' => $now, 'created_at' => $now],
            );
        }
        $backofficeGroupId = DB::table('groups')->where('code', 'BACKOFFICE_USERS')->value('id');
        DB::table('group_feature_assignments')->updateOrInsert(
            ['group_id' => $backofficeGroupId, 'feature_id' => DB::table('features')->where('code', 'backoffice.tasks')->value('id')],
            ['updated_at' => $now, 'created_at' => $now],
        );
        $employeeRoleId = DB::table('roles')->where('code', Role::EMPLOYEE)->value('id');
        DB::table('role_assignments')->updateOrInsert(
            ['id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'scenario-group-role-assignment:ALL_USERS:'.Role::EMPLOYEE)->toString()],
            ['subject_type' => 'group', 'subject_id' => $groupId, 'role_id' => $employeeRoleId, 'scope_type' => 'self', 'status' => 'active', 'updated_at' => $now, 'created_at' => $now],
        );
    }
}
