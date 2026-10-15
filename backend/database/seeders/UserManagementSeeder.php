<?php

namespace Database\Seeders;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\UserManagement\Commands\AddMembership;
use App\Domain\UserManagement\Commands\ChangeFieldAuthority;
use App\Domain\UserManagement\Commands\CreateGroup;
use App\Domain\UserManagement\Commands\CreateGroupType;
use App\Domain\UserManagement\Commands\LinkExternalIdentity;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 標準のGroupType・Group・所属・外部ID・項目の権限をCommand(操作者null)で作成し、イベントを記録する
 * (論点19 決定4)。既にある行は飛ばし、上書きしない(管理者が画面で変えた設定を壊さない)。
 */
class UserManagementSeeder extends Seeder
{
    public function run(): void
    {
        $bus = app(CommandBus::class);
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
            $order = $displayOrder++;
            if (DB::table('group_types')->where('code', $code)->exists()) {
                continue;
            }
            $bus->dispatch(new CreateGroupType(
                code: $code,
                name: $attributes['name'],
                displayOrder: $order,
                membershipLimitType: $attributes['membership_limit_type'],
                maxMembershipsPerUser: $attributes['max_memberships_per_user'],
                primaryMembershipRequired: $attributes['primary_membership_required'],
                maxPrimaryMemberships: $attributes['max_primary_memberships'],
                actorUserId: null,
                isSystem: true,
            ));
        }

        $typeId = (int) DB::table('group_types')->where('code', 'CUSTOM')->value('id');
        $groupIds = [];
        foreach ([
            'ALL_USERS' => '全利用者',
            'SYSTEM_ADMINISTRATORS' => 'システム管理者',
            'HUMAN_RESOURCES_USERS' => '人事管理利用者',
            'BACKOFFICE_USERS' => 'バックオフィス利用者',
        ] as $code => $name) {
            $existing = DB::table('groups')->where('code', $code)->value('id');
            if ($existing) {
                $groupIds[$code] = $existing;

                continue;
            }
            $groupIds[$code] = (string) Str::uuid();
            $bus->dispatch(new CreateGroup($groupIds[$code], $typeId, $name, $code, null, null, null));
        }

        $tenantId = SystemSetting::current()->m365_tenant_id;
        User::query()->each(function (User $user) use ($bus, $groupIds, $tenantId): void {
            if (! DB::table('memberships')->where('user_id', $user->id)->where('group_id', $groupIds['ALL_USERS'])->exists()) {
                $bus->dispatch(new AddMembership($user->id, $groupIds['ALL_USERS'], 'member', false, null));
            }

            // Microsoft Entra Tenantが未設定の環境では、LinkExternalIdentityが拒否するためリンクしない。
            if ($user->entra_user_id && $tenantId
                && ! DB::table('external_identities')->where('provider', 'MICROSOFT_ENTRA')->where('external_subject_id', $user->entra_user_id)->exists()) {
                $bus->dispatch(new LinkExternalIdentity($user->id, 'MICROSOFT_ENTRA', $tenantId, $user->entra_user_id, null, $user->email, null));
            }
        });

        foreach (['display_name', 'email', 'employee_number', 'department', 'job_title', 'employment_status', 'account_status', 'hire_date', 'termination_date', 'usage_start_date'] as $field) {
            if (! DB::table('field_authorities')->where('field_key', $field)->exists()) {
                $bus->dispatch(new ChangeFieldAuthority($field, 'LOCAL', null, null));
            }
        }
    }
}
