<?php

namespace Tests\Feature\UserManagement;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 有給・特別休暇の自動付与のユーザーごと有効/無効設定
 * (docs/changesets/20260904-paid-leave-auto-grant-per-user-toggle/spec.md)。
 */
class UserAutoGrantEnabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_users_default_to_auto_grant_enabled_for_both_leave_types(): void
    {
        $employee = User::factory()->create();

        $this->assertTrue($employee->paid_leave_auto_grant_enabled);
        $this->assertTrue($employee->special_leave_auto_grant_enabled);
    }

    public function test_hr_staff_can_disable_and_re_enable_paid_leave_auto_grant(): void
    {
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));
        $employee = User::factory()->create();

        $response = $this->actingAs($hr)->putJson("/api/users/{$employee->id}/paid-leave-auto-grant-enabled", [
            'enabled' => false,
        ]);

        $response->assertOk();
        $response->assertJsonPath('paid_leave_auto_grant_enabled', false);
        $this->assertFalse($employee->refresh()->paid_leave_auto_grant_enabled);

        $this->actingAs($hr)->putJson("/api/users/{$employee->id}/paid-leave-auto-grant-enabled", [
            'enabled' => true,
        ])->assertOk()->assertJsonPath('paid_leave_auto_grant_enabled', true);

        $this->assertTrue($employee->refresh()->paid_leave_auto_grant_enabled);
    }

    public function test_employee_without_permission_cannot_disable_paid_leave_auto_grant(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($employee)->putJson("/api/users/{$other->id}/paid-leave-auto-grant-enabled", [
            'enabled' => false,
        ])->assertForbidden();
    }

    public function test_hr_staff_can_disable_and_re_enable_special_leave_auto_grant(): void
    {
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));
        $employee = User::factory()->create();

        $response = $this->actingAs($hr)->putJson("/api/users/{$employee->id}/special-leave-auto-grant-enabled", [
            'enabled' => false,
        ]);

        $response->assertOk();
        $response->assertJsonPath('special_leave_auto_grant_enabled', false);
        $this->assertFalse($employee->refresh()->special_leave_auto_grant_enabled);

        $this->actingAs($hr)->putJson("/api/users/{$employee->id}/special-leave-auto-grant-enabled", [
            'enabled' => true,
        ])->assertOk()->assertJsonPath('special_leave_auto_grant_enabled', true);

        $this->assertTrue($employee->refresh()->special_leave_auto_grant_enabled);
    }

    public function test_employee_without_permission_cannot_disable_special_leave_auto_grant(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($employee)->putJson("/api/users/{$other->id}/special-leave-auto-grant-enabled", [
            'enabled' => false,
        ])->assertForbidden();
    }
}
