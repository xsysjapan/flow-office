<?php

namespace Tests\Feature\UserManagement;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GET /users・GET /users/{user} は入社日・退社日・雇用区分・ロールを含むため
 * role:admin,hr_staff限定。承認者選択(UserPicker)等、一般社員も使う軽量な検索は
 * GET /users/search を使う(機微な項目を返さない)。
 */
class UserSearchAndAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_plain_employee_cannot_list_users_via_the_admin_index(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->getJson('/api/users')->assertForbidden();
    }

    public function test_a_plain_employee_cannot_view_another_users_detail(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($employee)->getJson("/api/users/{$other->id}")->assertForbidden();
    }

    public function test_admin_can_list_and_view_users(): void
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));
        $other = User::factory()->create();

        $this->actingAs($admin)->getJson('/api/users')->assertOk();
        $this->actingAs($admin)->getJson("/api/users/{$other->id}")->assertOk();
    }

    public function test_a_plain_employee_can_search_users_but_only_gets_picker_safe_fields(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create(['name' => '検索対象太郎']);
        DB::table('features')->insert([
            'code' => 'administration',
            'name' => '管理',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($employee)->getJson('/api/users/search?q=検索対象');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $other->id);
        $response->assertJsonPath('data.0.name', $other->name);
        $response->assertJsonMissingPath('data.0.hire_date');
        $response->assertJsonMissingPath('data.0.termination_date');
        $response->assertJsonMissingPath('data.0.employment_status');
        $response->assertJsonMissingPath('data.0.roles');
    }

    /**
     * 備品貸出申請の承認者選択(asset.manage絞り込み)等、`?permission=`で
     * globalスコープの権限保有者だけに絞り込めること。
     */
    public function test_search_can_be_filtered_to_users_holding_a_global_permission(): void
    {
        $employee = User::factory()->create();
        $manager = User::factory()->create(['name' => '備品担当太郎']);
        $nonManager = User::factory()->create(['name' => '備品担当外花子']);
        $this->grantGlobalPermission($manager, 'asset.manage');

        $response = $this->actingAs($employee)->getJson('/api/users/search?permission=asset.manage');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($manager->id));
        $this->assertFalse($ids->contains($nonManager->id));
    }

    public function test_search_without_permission_returns_all_matching_users(): void
    {
        $employee = User::factory()->create();
        $someone = User::factory()->create(['name' => '任意の社員']);

        $response = $this->actingAs($employee)->getJson('/api/users/search?q=任意の社員');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $someone->id);
    }

    /**
     * grantSelfPermission()はself scopeでEmployee共有ロールへ付与するため、globalスコープの
     * 絞り込みテストには使えない。専用RoleでglobalスコープのRoleAssignmentを作る。
     */
    private function grantGlobalPermission(User $user, string $code): void
    {
        [$resource, $action] = explode('.', $code, 2);
        DB::table('permissions')->updateOrInsert(
            ['code' => $code],
            ['resource' => $resource, 'action' => $action, 'created_at' => now(), 'updated_at' => now()],
        );
        $permissionId = DB::table('permissions')->where('code', $code)->value('id');
        DB::table('permission_scope_types')->insertOrIgnore(['permission_id' => $permissionId, 'scope_type' => 'global']);

        $role = Role::query()->create(['code' => 'GLOBAL_PERM_'.Str::upper(Str::random(12)), 'name' => 'Global permission (test)']);
        DB::table('permission_role')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permissionId]);

        RoleAssignment::query()->create([
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => 'global',
            'scope_group_id' => null,
            'include_descendants' => false,
            'status' => 'active',
            'assigned_by' => $user->id,
        ]);
    }
}
