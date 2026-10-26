<?php

namespace Tests\Feature\PaidLeaveAccount;

use App\Models\CompanyCalendar;
use App\Models\EmployeeCalendarEntry;
use App\Models\PaidLeaveGrant;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 管理者による有給付与の付与日数の変更(PUT /paid-leave/grants/{grant}/granted-days)と、
 * 付与一覧の is_latest(集約の「最新の付与」の判定)。
 */
class PaidLeaveGrantAmountChangeTest extends TestCase
{
    use RefreshDatabase;

    private function hr(): User
    {
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));

        return $hr;
    }

    private function grantOn(User $hr, User $employee, string $grantedOn, string $expiresOn, float $days = 10): string
    {
        return $this->actingAs($hr)->postJson('/api/paid-leave/grants', [
            'user_id' => $employee->id,
            'granted_on' => $grantedOn,
            'expires_on' => $expiresOn,
            'granted_days' => $days,
            'grant_reason' => '付与',
        ])->assertCreated()->json('id');
    }

    /** @param string[] $dates */
    private function prepareWorkingDays(User $employee, array $dates): void
    {
        $calendar = CompanyCalendar::query()->create(['name' => '2026年度', 'week_starts_on' => 1]);
        $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);
        $workStyle = WorkStyle::query()->create([
            'code' => 'standard-'.$employee->id, 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
        foreach ($dates as $date) {
            EmployeeCalendarEntry::query()->create([
                'user_id' => $employee->id, 'work_date' => $date, 'work_style_id' => $workStyle->id,
                'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
                'planned_start_at' => $date.' 09:00:00', 'planned_end_at' => $date.' 18:00:00',
                'planned_break_minutes' => 60,
            ]);
        }
    }

    private function approveFullDayLeave(User $employee, User $approver, string $date): void
    {
        $requestId = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => $date,
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');
        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$requestId}/approve")->assertOk();
    }

    private function balance(User $employee, string $column): float
    {
        return (float) DB::table('paid_leave_balances')->where('user_id', $employee->id)->value($column);
    }

    private function amountEventCount(): int
    {
        return DB::table('stored_events')->where('event_class', 'paid_leave_account.grant_amount_changed')->count();
    }

    public function test_the_latest_grant_amount_can_be_increased_and_reduced(): void
    {
        $hr = $this->hr();
        $employee = User::factory()->create();
        $grantId = $this->grantOn($hr, $employee, '2026-07-01', '2028-06-30');

        $response = $this->actingAs($hr)->putJson("/api/paid-leave/grants/{$grantId}/granted-days", ['granted_days' => 12, 'reason' => '訂正']);
        $response->assertOk();
        $this->assertEquals(12.0, $response->json('granted_days'));
        $this->assertEquals(12.0, $response->json('remaining_days'));
        $this->assertEquals(12.0, (float) PaidLeaveGrant::query()->findOrFail($grantId)->remaining_days);
        $this->assertEquals(12.0, $this->balance($employee, 'available_days'));

        $this->actingAs($hr)->putJson("/api/paid-leave/grants/{$grantId}/granted-days", ['granted_days' => 7.5])->assertOk();
        $grant = PaidLeaveGrant::query()->findOrFail($grantId);
        $this->assertEquals(7.5, (float) $grant->granted_days);
        $this->assertEquals(7.5, (float) $grant->remaining_days);
        $this->assertEquals(7.5, $this->balance($employee, 'available_days'));
    }

    public function test_a_non_latest_grant_cannot_be_changed(): void
    {
        $hr = $this->hr();
        $employee = User::factory()->create();
        $old = $this->grantOn($hr, $employee, '2026-07-01', '2028-06-30');
        $this->grantOn($hr, $employee, '2027-07-01', '2029-06-30');

        $this->actingAs($hr)->putJson("/api/paid-leave/grants/{$old}/granted-days", ['granted_days' => 12])->assertStatus(422);

        $this->assertEquals(10.0, (float) PaidLeaveGrant::query()->findOrFail($old)->granted_days);
        $this->assertSame(0, $this->amountEventCount());
    }

    public function test_a_revoked_grant_cannot_be_changed(): void
    {
        $hr = $this->hr();
        $employee = User::factory()->create();
        $grantId = $this->grantOn($hr, $employee, '2026-07-01', '2028-06-30');
        $this->actingAs($hr)->postJson("/api/paid-leave/grants/{$grantId}/revoke")->assertOk();

        $this->actingAs($hr)->putJson("/api/paid-leave/grants/{$grantId}/granted-days", ['granted_days' => 12])->assertStatus(422);
    }

    public function test_reducing_below_the_allocated_days_is_rejected_and_changes_nothing(): void
    {
        $hr = $this->hr();
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $this->prepareWorkingDays($employee, ['2026-08-10']);
        $grantId = $this->grantOn($hr, $employee, '2026-07-01', '2028-06-30');
        $this->approveFullDayLeave($employee, $approver, '2026-08-10');

        $this->actingAs($hr)->putJson("/api/paid-leave/grants/{$grantId}/granted-days", ['granted_days' => 0.5])->assertStatus(422);

        $this->assertEquals(10.0, (float) PaidLeaveGrant::query()->findOrFail($grantId)->granted_days);
        $this->assertEquals(9.0, (float) PaidLeaveGrant::query()->findOrFail($grantId)->remaining_days);
        $this->assertSame(0, $this->amountEventCount());
    }

    public function test_increasing_allocates_the_unallocated_usage(): void
    {
        $hr = $this->hr();
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $this->prepareWorkingDays($employee, ['2026-08-10', '2026-08-11']);
        $grantId = $this->grantOn($hr, $employee, '2026-07-01', '2028-06-30', 1);
        $this->approveFullDayLeave($employee, $approver, '2026-08-10');
        $this->approveFullDayLeave($employee, $approver, '2026-08-11');
        $this->assertEquals(1.0, $this->balance($employee, 'unallocated_days'));

        $this->actingAs($hr)->putJson("/api/paid-leave/grants/{$grantId}/granted-days", ['granted_days' => 2])->assertOk();

        $grant = PaidLeaveGrant::query()->findOrFail($grantId);
        $this->assertEquals(2.0, (float) $grant->granted_days);
        $this->assertEquals(0.0, (float) $grant->remaining_days);
        $this->assertEquals(0.0, $this->balance($employee, 'unallocated_days'));
    }

    public function test_the_same_days_record_no_event_and_return_200(): void
    {
        $hr = $this->hr();
        $employee = User::factory()->create();
        $grantId = $this->grantOn($hr, $employee, '2026-07-01', '2028-06-30');

        $this->actingAs($hr)->putJson("/api/paid-leave/grants/{$grantId}/granted-days", ['granted_days' => 10])->assertOk();

        $this->assertSame(0, $this->amountEventCount());
    }

    public function test_a_user_without_leave_manage_gets_403(): void
    {
        $hr = $this->hr();
        $employee = User::factory()->create();
        $grantId = $this->grantOn($hr, $employee, '2026-07-01', '2028-06-30');

        $this->actingAs($employee)->putJson("/api/paid-leave/grants/{$grantId}/granted-days", ['granted_days' => 12])->assertForbidden();
        $this->assertEquals(10.0, (float) PaidLeaveGrant::query()->findOrFail($grantId)->granted_days);
    }

    public function test_less_than_half_a_day_and_missing_days_are_validation_errors(): void
    {
        $hr = $this->hr();
        $employee = User::factory()->create();
        $grantId = $this->grantOn($hr, $employee, '2026-07-01', '2028-06-30');

        $this->actingAs($hr)->putJson("/api/paid-leave/grants/{$grantId}/granted-days", ['granted_days' => 0.4])->assertStatus(422);
        $this->actingAs($hr)->putJson("/api/paid-leave/grants/{$grantId}/granted-days", [])->assertStatus(422);
        $this->actingAs($hr)->putJson("/api/paid-leave/grants/{$grantId}/granted-days", ['granted_days' => 12, 'reason' => str_repeat('あ', 256)])->assertStatus(422);
    }

    public function test_is_latest_is_true_for_exactly_one_grant_even_when_granted_on_is_the_same(): void
    {
        $hr = $this->hr();
        $employee = User::factory()->create();
        $this->grantOn($hr, $employee, '2026-07-01', '2028-06-30');
        $second = $this->grantOn($hr, $employee, '2026-07-01', '2028-06-30', 5);

        $grants = $this->actingAs($hr)->getJson("/api/paid-leave/grants/user/{$employee->id}")->assertOk()->json();

        $this->assertCount(2, $grants);
        $this->assertCount(1, array_filter($grants, fn ($g) => $g['is_latest'] === true));
        $this->assertSame($second, collect($grants)->firstWhere('is_latest', true)['id']);

        $this->actingAs($hr)->postJson("/api/paid-leave/grants/{$second}/revoke")->assertOk();
        $grants = $this->actingAs($hr)->getJson("/api/paid-leave/grants/user/{$employee->id}")->json();
        $revoked = collect($grants)->firstWhere('id', $second);
        $this->assertFalse($revoked['is_latest']);
        $this->assertCount(1, array_filter($grants, fn ($g) => $g['is_latest'] === true));
    }

    public function test_my_grants_do_not_include_is_latest(): void
    {
        $hr = $this->hr();
        $employee = User::factory()->create();
        $this->grantOn($hr, $employee, '2026-07-01', '2028-06-30');

        $grants = $this->actingAs($employee)->getJson('/api/paid-leave/grants/mine')->assertOk()->json();
        $this->assertArrayNotHasKey('is_latest', $grants[0]);
    }
}
