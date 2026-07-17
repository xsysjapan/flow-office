<?php

namespace Tests\Feature\User;

use App\Models\EmploymentCategory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 雇用区分マスタ(正社員/契約社員/パート/アルバイト/嘱託等)。work_styles(労働時間制度)とは
 * 独立した軸として管理する。
 */
class EmploymentCategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        return $admin;
    }

    public function test_any_authenticated_user_can_list_employment_categories(): void
    {
        $user = User::factory()->create();
        EmploymentCategory::query()->create(['code' => 'regular', 'name' => '正社員']);

        $response = $this->actingAs($user)->getJson('/api/employment-categories');

        $response->assertOk()->assertJsonFragment(['code' => 'regular', 'name' => '正社員']);
    }

    public function test_an_admin_can_create_an_employment_category(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->postJson('/api/employment-categories', [
            'code' => 'part_time',
            'name' => 'パート',
        ]);

        $response->assertCreated()->assertJsonPath('code', 'part_time');
        $this->assertDatabaseHas('employment_categories', ['code' => 'part_time', 'name' => 'パート']);
    }

    public function test_a_non_admin_cannot_create_an_employment_category(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/employment-categories', [
            'code' => 'part_time',
            'name' => 'パート',
        ]);

        $response->assertForbidden();
    }

    public function test_duplicate_code_is_rejected(): void
    {
        $admin = $this->makeAdmin();
        EmploymentCategory::query()->create(['code' => 'part_time', 'name' => 'パート']);

        $response = $this->actingAs($admin)->postJson('/api/employment-categories', [
            'code' => 'part_time',
            'name' => 'パート(重複)',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('code');
    }
}
