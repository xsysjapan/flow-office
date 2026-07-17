<?php

namespace Tests\Feature\PaidLeave;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-P001/UC-P002: 有給残数管理の土台。
 */
class PaidLeaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_staff_can_grant_paid_leave_and_employee_can_see_remaining_days(): void
    {
        $hr = User::factory()->create();
        $hr->roles()->attach(Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));
        $employee = User::factory()->create();

        $grantResponse = $this->actingAs($hr)->postJson('/api/paid-leave/grants', [
            'user_id' => $employee->id,
            'granted_on' => '2026-07-01',
            'expires_on' => '2028-06-30',
            'granted_days' => 10,
            'grant_reason' => '初回付与',
        ]);
        $grantResponse->assertCreated();
        $this->assertEquals(10.0, $grantResponse->json('remaining_days'));

        $mine = $this->actingAs($employee)->getJson('/api/paid-leave/grants/mine');
        $mine->assertOk();
        $this->assertCount(1, $mine->json());
    }

    public function test_employee_cannot_grant_paid_leave(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/paid-leave/grants', [
            'user_id' => $other->id,
            'granted_on' => '2026-07-01',
            'expires_on' => '2028-06-30',
            'granted_days' => 10,
        ])->assertForbidden();
    }
}
