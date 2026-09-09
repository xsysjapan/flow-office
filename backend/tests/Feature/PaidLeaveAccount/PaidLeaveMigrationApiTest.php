<?php

namespace Tests\Feature\PaidLeaveAccount;

use App\Models\PaidLeaveGrant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 最終Phase(データ移行)。`POST /api/paid-leave/migrate`(管理者権限限定・1社員分)の
 * 認可・レスポンスを検証する。
 */
class PaidLeaveMigrationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_staff_can_migrate_a_single_employee(): void
    {
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));
        $employee = User::factory()->create();

        $response = $this->actingAs($hr)->postJson('/api/paid-leave/migrate', [
            'user_id' => $employee->id,
            'cutover_date' => '2026-04-01',
            'grants' => [
                [
                    'original_granted_on' => '2025-04-01',
                    'original_granted_days' => 10.0,
                    'remaining_days_at_cutover' => 6.0,
                    'expires_on' => '2027-04-01',
                    'mode' => 'A',
                ],
            ],
        ]);

        $response->assertNoContent();

        $grant = PaidLeaveGrant::query()->where('user_id', $employee->id)->sole();
        $this->assertEquals('migration', $grant->source);
        $this->assertEquals(6.0, (float) $grant->remaining_days);
    }

    public function test_non_admin_employee_cannot_migrate(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();

        $response = $this->actingAs($employee)->postJson('/api/paid-leave/migrate', [
            'user_id' => $other->id,
            'cutover_date' => '2026-04-01',
            'grants' => [],
        ]);

        $response->assertForbidden();
    }
}
