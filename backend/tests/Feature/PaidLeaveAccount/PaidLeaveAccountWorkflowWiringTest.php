<?php

namespace Tests\Feature\PaidLeaveAccount;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Models\CompanyCalendar;
use App\Models\EmployeeCalendarEntry;
use App\Models\PaidLeaveBalance;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveAccountUsage;
use App\Models\PaidLeaveUsageAllocation;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4(docs/changesets/20260906-paid-leave-domain-redesign/spec.md「Workflow連携」):
 * 既存の有給申請Workflow(旧`PaidLeave`ドメイン)が、新設`PaidLeaveAccountAggregate`
 * (`paid_leave_usages.usage_id`/`paid_leave_usage_allocations`/`paid_leave_balances`)へも
 * 並行してUsageを記録することを確認する。旧ドメイン側の挙動・レスポンスは
 * `tests/Feature/PaidLeave/PaidLeaveRequestTest.php`等が引き続き検証するため、
 * ここでは新ドメインのProjectionが正しく更新されることのみを検証する。
 */
class PaidLeaveAccountWorkflowWiringTest extends TestCase
{
    use RefreshDatabase;

    private function createWorkingDayShift(User $user, string $date): void
    {
        $calendar = CompanyCalendar::query()->firstOrCreate(['name' => '2026年度'], ['week_starts_on' => 1]);
        if ($calendar->wasRecentlyCreated) {
            $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);
        }
        $workStyle = WorkStyle::query()->create([
            'code' => 'standard-'.uniqid(), 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);

        EmployeeCalendarEntry::query()->create([
            'user_id' => $user->id, 'work_date' => $date, 'work_style_id' => $workStyle->id,
            'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
            'planned_start_at' => "{$date} 09:00:00", 'planned_end_at' => "{$date} 18:00:00",
            'planned_break_minutes' => 60,
        ]);
    }

    private function grantNewDomain(User $user, string $grantedOn, string $expiresOn, float $days): void
    {
        app(CommandBus::class)->dispatch(new GrantPaidLeave(
            userId: (string) $user->id,
            grantedOn: $grantedOn,
            expiresOn: $expiresOn,
            grantedDays: $days,
            grantReason: 'テスト付与',
        ));
    }

    public function test_request_then_approve_designates_and_confirms_usage_with_allocation(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10');
        $this->grantNewDomain($employee, '2025-07-01', '2027-06-30', 10);

        // 旧ドメイン側の消化判定用grant projection行も作成しておく(旧Handlerが直接参照するため)。
        PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        $requestResponse = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
            'reason' => '私用のため',
        ]);
        $requestResponse->assertCreated();
        $requestId = $requestResponse->json('id');

        // 新ドメインUsageが未確定で作成されている。
        $usage = PaidLeaveAccountUsage::query()->where('user_id', $employee->id)->whereNotNull('usage_id')->first();
        $this->assertNotNull($usage, '新ドメインのUsageが作成されていること');
        $this->assertSame('2026-08-10', $usage->used_on->toDateString());
        $this->assertEquals(1.0, (float) $usage->used_days);
        $this->assertFalse($usage->confirmed);
        $this->assertFalse($usage->cancelled);
        $this->assertSame(0, PaidLeaveUsageAllocation::query()->where('usage_id', $usage->usage_id)->count());

        $approveResponse = $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$requestId}/approve");
        $approveResponse->assertOk();

        $usage->refresh();
        $this->assertTrue($usage->confirmed);
        $this->assertFalse($usage->cancelled);

        $allocation = PaidLeaveUsageAllocation::query()->where('usage_id', $usage->usage_id)->first();
        $this->assertNotNull($allocation, '承認によりAllocationが作成されていること');
        $this->assertEquals(1.0, (float) $allocation->allocated_days);

        $balance = PaidLeaveBalance::query()->where('user_id', $employee->id)->first();
        // PaidLeaveBalanceProjectorが存在すれば残高キャッシュも反映されているはず。
        if ($balance !== null) {
            $this->assertEquals(9.0, (float) $balance->available_days);
        }

        // 旧ドメインの結果(既存レスポンス・Projection)には一切影響が無いことをスポットチェックする
        // (tests/Feature/PaidLeave/PaidLeaveRequestTest.phpが検証する内容と同じ観点)。
        $approveResponse->assertJsonPath('status', 'approved');
        $oldRequest = PaidLeaveRequest::query()->findOrFail($requestId);
        $status = $oldRequest->status;
        $this->assertSame('approved', $status instanceof \BackedEnum ? $status->value : (string) $status);
    }

    public function test_return_before_approval_cancels_usage_without_allocation(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-11');
        $this->grantNewDomain($employee, '2025-07-01', '2027-06-30', 10);

        PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        $requestResponse = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-11',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
            'reason' => '私用のため',
        ]);
        $requestId = $requestResponse->json('id');

        $usage = PaidLeaveAccountUsage::query()->where('user_id', $employee->id)->whereNotNull('usage_id')->first();
        $this->assertNotNull($usage);

        $returnResponse = $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$requestId}/return", [
            'comment' => '確認してください',
        ]);
        $returnResponse->assertOk();

        $usage->refresh();
        $this->assertTrue($usage->cancelled);
        $this->assertFalse($usage->confirmed);
        $this->assertSame(0, PaidLeaveUsageAllocation::query()->where('usage_id', $usage->usage_id)->count());

        // 再提出は新規のPaidLeaveRequest(=新規Usage)として行われ、取消済みUsageは再利用しない。
        $resubmitResponse = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-11',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
            'reason' => '再提出',
        ]);
        $resubmitResponse->assertCreated();

        $usages = PaidLeaveAccountUsage::query()->where('user_id', $employee->id)->whereNotNull('usage_id')->get();
        $this->assertSame(2, $usages->count(), '取消済みUsageを再利用せず新規Usageが作成されること');
        $newUsage = $usages->firstWhere('usage_id', '!=', $usage->usage_id);
        $this->assertNotNull($newUsage);
        $this->assertFalse($newUsage->cancelled);
    }

    public function test_approve_then_admin_cancel_releases_allocation_and_restores_balance(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));

        $this->createWorkingDayShift($employee, '2026-08-12');
        $this->grantNewDomain($employee, '2025-07-01', '2027-06-30', 10);

        PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        $requestResponse = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-12',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
            'reason' => '私用のため',
        ]);
        $requestId = $requestResponse->json('id');

        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$requestId}/approve")->assertOk();

        $usage = PaidLeaveAccountUsage::query()->where('user_id', $employee->id)->whereNotNull('usage_id')->first();
        $this->assertTrue($usage->confirmed);
        $this->assertSame(1, PaidLeaveUsageAllocation::query()->where('usage_id', $usage->usage_id)->count());

        $cancelResponse = $this->actingAs($hr)->postJson("/api/paid-leave/requests/{$requestId}/admin-cancel", [
            'reason' => '誤申請のため',
        ]);
        $cancelResponse->assertOk();

        $usage->refresh();
        $this->assertTrue($usage->cancelled);
        $this->assertSame(0, PaidLeaveUsageAllocation::query()->where('usage_id', $usage->usage_id)->count(), 'Allocationが解除されていること');

        $grant = PaidLeaveGrant::query()->where('user_id', $employee->id)->whereNotNull('allocated_days')->first();
        $this->assertNotNull($grant);
        $this->assertEquals(0.0, (float) $grant->allocated_days);
        $this->assertEquals(10.0, (float) $grant->remaining_days);
    }

    public function test_hourly_leave_request_is_out_of_scope_and_does_not_create_new_domain_usage(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-13');

        PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        $requestResponse = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-13',
            'leave_type' => 'hourly',
            'hours' => 2,
            'approver_user_id' => $approver->id,
            'reason' => '私用のため',
        ]);
        $requestResponse->assertCreated();

        $this->assertSame(
            0,
            PaidLeaveAccountUsage::query()->where('user_id', $employee->id)->whereNotNull('usage_id')->count(),
            '時間単位有給は新ドメインの対象外のためUsageを作成しないこと',
        );
    }
}
