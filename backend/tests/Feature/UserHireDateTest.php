<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 入社日の設定 (docs/09-usecases-paid-leave.md UC-P002 で使う継続勤務期間の基準日)。
 */
class UserHireDateTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_staff_can_set_a_users_hire_date(): void
    {
        $hr = User::factory()->create();
        $hr->roles()->attach(Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));
        $employee = User::factory()->create();

        $response = $this->actingAs($hr)->putJson("/api/users/{$employee->id}/hire-date", [
            'hire_date' => '2024-04-01',
        ]);

        $response->assertOk();
        $response->assertJsonPath('hire_date', '2024-04-01');
        $this->assertSame('2024-04-01', $employee->refresh()->hire_date->toDateString());
    }

    public function test_employee_cannot_set_a_hire_date(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($employee)->putJson("/api/users/{$other->id}/hire-date", [
            'hire_date' => '2024-04-01',
        ])->assertForbidden();
    }

    public function test_hr_staff_can_set_and_clear_a_users_termination_date(): void
    {
        $hr = User::factory()->create();
        $hr->roles()->attach(Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));
        $employee = User::factory()->create(['hire_date' => '2024-04-01']);

        $this->actingAs($hr)->putJson("/api/users/{$employee->id}/termination-date", [
            'termination_date' => '2026-03-31',
        ])->assertOk()->assertJsonPath('termination_date', '2026-03-31');

        $this->assertSame('2026-03-31', $employee->refresh()->termination_date->toDateString());

        $this->actingAs($hr)->putJson("/api/users/{$employee->id}/termination-date", [
            'termination_date' => null,
        ])->assertOk()->assertJsonPath('termination_date', null);

        $this->assertNull($employee->refresh()->termination_date);
    }

    public function test_hire_and_termination_dates_must_form_a_valid_employment_period(): void
    {
        $hr = User::factory()->create();
        $hr->roles()->attach(Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));
        $employee = User::factory()->create(['hire_date' => '2024-04-01', 'termination_date' => '2026-03-31']);

        $this->actingAs($hr)->putJson("/api/users/{$employee->id}/hire-date", [
            'hire_date' => '2026-04-01',
        ])->assertStatus(422);

        $this->actingAs($hr)->putJson("/api/users/{$employee->id}/termination-date", [
            'termination_date' => '2024-03-31',
        ])->assertStatus(422);
    }
}
