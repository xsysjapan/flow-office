<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserManagementSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $groupTypes = [
            'ORGANIZATION' => ['name' => '組織', 'membership_limit_type' => 'unlimited', 'max_memberships_per_user' => null, 'primary_membership_required' => true, 'max_primary_memberships' => 1],
            'EMPLOYMENT' => ['name' => '雇用区分', 'membership_limit_type' => 'limited', 'max_memberships_per_user' => 1, 'primary_membership_required' => true, 'max_primary_memberships' => 1],
            'PROJECT' => ['name' => 'プロジェクト', 'membership_limit_type' => 'unlimited', 'max_memberships_per_user' => null, 'primary_membership_required' => false, 'max_primary_memberships' => null],
            'COMMITTEE' => ['name' => '委員会', 'membership_limit_type' => 'unlimited', 'max_memberships_per_user' => null, 'primary_membership_required' => false, 'max_primary_memberships' => null],
            'LOCATION' => ['name' => '拠点', 'membership_limit_type' => 'limited', 'max_memberships_per_user' => 1, 'primary_membership_required' => false, 'max_primary_memberships' => 1],
            'APPROVAL' => ['name' => '承認担当', 'membership_limit_type' => 'unlimited', 'max_memberships_per_user' => null, 'primary_membership_required' => false, 'max_primary_memberships' => null],
            'CUSTOM' => ['name' => '共通・独自', 'membership_limit_type' => 'unlimited', 'max_memberships_per_user' => null, 'primary_membership_required' => false, 'max_primary_memberships' => null],
        ];

        $displayOrder = 0;
        foreach ($groupTypes as $code => $attributes) {
            DB::table('group_types')->updateOrInsert(
                ['code' => $code],
                [...$attributes, 'display_order' => $displayOrder++, 'is_system' => true, 'status' => 'active', 'updated_at' => $now, 'created_at' => $now],
            );
        }

        $typeId = DB::table('group_types')->where('code', 'CUSTOM')->value('id');
        $groupIds = [];
        foreach ([
            'ALL_USERS' => '全利用者',
            'SYSTEM_ADMINISTRATORS' => 'システム管理者',
            'BACKOFFICE_USERS' => 'バックオフィス利用者',
        ] as $code => $name) {
            $groupIds[$code] = DB::table('groups')->where('code', $code)->value('id') ?: (string) Str::uuid();
            DB::table('groups')->updateOrInsert(
                ['code' => $code],
                ['id' => $groupIds[$code], 'group_type_id' => $typeId, 'name' => $name, 'status' => 'active', 'updated_at' => $now, 'created_at' => $now],
            );
        }

        User::query()->with('roles')->each(function (User $user) use ($groupIds, $now): void {
            DB::table('memberships')->updateOrInsert(
                ['user_id' => $user->id, 'group_id' => $groupIds['ALL_USERS']],
                ['membership_kind' => 'member', 'updated_at' => $now, 'created_at' => $now],
            );

            if ($user->roles->contains('code', Role::ADMIN)) {
                DB::table('memberships')->updateOrInsert(
                    ['user_id' => $user->id, 'group_id' => $groupIds['SYSTEM_ADMINISTRATORS']],
                    ['membership_kind' => 'member', 'updated_at' => $now, 'created_at' => $now],
                );
            }

            if ($user->roles->pluck('code')->intersect([
                Role::BACKOFFICE_STAFF,
                Role::ACCOUNTING_STAFF,
                Role::GENERAL_AFFAIRS_STAFF,
                Role::HR_STAFF,
                Role::ADMIN,
            ])->isNotEmpty()) {
                DB::table('memberships')->updateOrInsert(
                    ['user_id' => $user->id, 'group_id' => $groupIds['BACKOFFICE_USERS']],
                    ['membership_kind' => 'member', 'updated_at' => $now, 'created_at' => $now],
                );
            }

            if ($user->entra_user_id) {
                DB::table('external_identities')->updateOrInsert(
                    ['provider' => 'MICROSOFT_ENTRA', 'external_subject_id' => $user->entra_user_id],
                    ['user_id' => $user->id, 'external_tenant_id' => SystemSetting::current()->m365_tenant_id, 'email' => $user->email, 'status' => 'active', 'linked_at' => $now, 'updated_at' => $now, 'created_at' => $now],
                );
            }
        });

        foreach (['display_name', 'email', 'employee_number', 'department', 'job_title', 'employment_status', 'account_status', 'hire_date', 'termination_date', 'usage_start_date'] as $field) {
            DB::table('field_authorities')->updateOrInsert(
                ['field_key' => $field],
                ['authority_type' => 'LOCAL', 'updated_at' => $now, 'created_at' => $now],
            );
        }
    }
}
