<?php

namespace Tests\Feature\User;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));
        $other = User::factory()->create();

        $this->actingAs($admin)->getJson('/api/users')->assertOk();
        $this->actingAs($admin)->getJson("/api/users/{$other->id}")->assertOk();
    }

    public function test_a_plain_employee_can_search_users_but_only_gets_picker_safe_fields(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create(['name' => '検索対象太郎']);

        $response = $this->actingAs($employee)->getJson('/api/users/search?q=検索対象');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $other->id);
        $response->assertJsonPath('data.0.name', $other->name);
        $response->assertJsonMissingPath('data.0.hire_date');
        $response->assertJsonMissingPath('data.0.termination_date');
        $response->assertJsonMissingPath('data.0.employment_status');
        $response->assertJsonMissingPath('data.0.roles');
    }
}
