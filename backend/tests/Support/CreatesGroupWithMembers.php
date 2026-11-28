<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 所属(memberships)を持つグループをテストの前提として作る。
 */
trait CreatesGroupWithMembers
{
    /**
     * @param  list<User>  $members
     */
    protected function makeGroupWithMembers(array $members, ?string $parentGroupId = null): string
    {
        $groupTypeId = DB::table('group_types')->where('code', 'BULK_GRANT_TEST')->value('id')
            ?? DB::table('group_types')->insertGetId(['code' => 'BULK_GRANT_TEST', 'name' => 'Bulk grant test', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $groupId = (string) Str::uuid();
        DB::table('groups')->insert([
            'id' => $groupId, 'group_type_id' => $groupTypeId, 'parent_group_id' => $parentGroupId, 'name' => '付与テスト用グループ',
            'code' => 'BULK_GRANT_'.Str::upper(Str::random(12)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($members as $member) {
            DB::table('memberships')->insert([
                'user_id' => $member->id, 'group_id' => $groupId, 'membership_kind' => 'member', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $groupId;
    }
}
