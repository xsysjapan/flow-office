<?php

namespace Tests\Feature\PaidLeaveAccount;

use App\Models\CompanyCalendar;
use App\Models\EmployeeCalendarEntry;
use App\Models\PaidLeaveGrant;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 管理者による有給付与の取消。未消化の付与のみ取り消せる。
 */
class PaidLeaveGrantRevocationTest extends TestCase
{
    use RefreshDatabase;

    private function grantFor(User $hr, User $employee): string
    {
        return $this->actingAs($hr)->postJson('/api/paid-leave/grants', [
            'user_id' => $employee->id,
            'granted_on' => '2026-07-01',
            'expires_on' => '2028-06-30',
            'granted_days' => 10,
            'grant_reason' => '初回付与',
        ])->assertCreated()->json('id');
    }

    public function test_hr_staff_can_revoke_an_unused_grant(): void
    {
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));
        $employee = User::factory()->create();

        $grantId = $this->grantFor($hr, $employee);

        $response = $this->actingAs($hr)->postJson("/api/paid-leave/grants/{$grantId}/revoke", [
            'reason' => '入力誤り',
        ]);

        $response->assertOk();
        $this->assertSame('revoked', $response->json('status'));

        $grant = PaidLeaveGrant::query()->findOrFail($grantId);
        $this->assertSame('revoked', $grant->status);
        $this->assertNotNull($grant->revoked_at);
        $this->assertSame($hr->id, $grant->revoked_by_user_id);
        $this->assertSame('入力誤り', $grant->revoke_reason);
    }

    /**
     * Phase 5(cutover)により消化済みGrantの取消可否が変わった: 旧ドメインは消化済み
     * (used_days > 0)なら取消をブロックしていたが、`PaidLeaveAccountAggregate`の不変条件5
     * (docs/changesets/20260906-paid-leave-domain-redesign/spec.md「仕様確定事項」)は
     * 「Grant取消時は当該Grantの全Allocationを解除する(Usage自体は取消しない)」と明記して
     * おり、消化済みでも取消自体は成立させ、Allocationを解除して残高を復元する設計に変わった。
     */
    public function test_revoking_a_grant_with_existing_allocation_releases_it_and_restores_the_balance(): void
    {
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));
        $employee = User::factory()->create();
        $approver = User::factory()->create();

        $calendar = CompanyCalendar::query()->create(['name' => '2026年度', 'week_starts_on' => 1]);
        $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);
        $workStyle = WorkStyle::query()->create([
            'code' => 'standard-'.$employee->id, 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
        EmployeeCalendarEntry::query()->create([
            'user_id' => $employee->id, 'work_date' => '2026-08-10', 'work_style_id' => $workStyle->id,
            'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
            'planned_start_at' => '2026-08-10 09:00:00', 'planned_end_at' => '2026-08-10 18:00:00',
            'planned_break_minutes' => 60,
        ]);

        $grantId = $this->grantFor($hr, $employee);

        // Allocation(=Grantの消化)を実際に発生させる(有給申請→承認)ことで、
        // PaidLeaveAccountAggregateの不変条件4(Allocation済みGrantの取消不可)を検証する。
        $requestId = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');
        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$requestId}/approve")->assertOk();

        $grant = PaidLeaveGrant::query()->findOrFail($grantId);
        $this->assertEquals(9.0, (float) $grant->remaining_days);

        $response = $this->actingAs($hr)->postJson("/api/paid-leave/grants/{$grantId}/revoke");

        $response->assertOk();
        $this->assertSame('revoked', $response->json('status'));
        $this->assertEquals(0.0, (float) $grant->refresh()->allocated_days);
    }

    public function test_employee_cannot_revoke_a_grant(): void
    {
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));
        $employee = User::factory()->create();

        $grantId = $this->grantFor($hr, $employee);

        $this->actingAs($employee)->postJson("/api/paid-leave/grants/{$grantId}/revoke")->assertForbidden();
    }
}
