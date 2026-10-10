<?php

namespace Tests\Feature\PaidLeaveRequest;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeave\Commands\ApprovePaidLeaveRequest;
use App\Domain\PaidLeave\Commands\CancelPaidLeaveRequest;
use App\Domain\PaidLeave\Commands\RequestPaidLeave;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Domain\Workflow\Commands\ApproveWorkflowRequest;
use App\Models\CompanyCalendar;
use App\Models\EmployeeCalendarEntry;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveUsage;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 有給休暇の申請・承認の連鎖を、APIとCommand起点で通すシナリオテスト(domain-testスキル。UI非依存、SQLite+Eloquent)。
 *
 * 文脈は「申請・承認(ワークフロー)」「休暇申請(有給申請の集約・paid_leave_requests)」「残数・使用(有給口座の消化記録)」。
 * 各ステップの後で、ワークフローの状態・有給申請の状態・消化記録(取消されていない件数と確定済みの件数)・残数を確認する。
 * 勤怠日の作業内容・休暇ビューは勤怠側の作業(別変更)で扱うため、ここでは確認しない。
 */
class PaidLeaveRequestScenarioTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-08-10';

    private User $employee;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = User::factory()->create();
        $this->approver = User::factory()->create();
        $this->createWorkingDays($this->employee, [self::DATE, '2026-08-11', '2026-08-12']);
    }

    // ---- 申請・承認(ワークフロー)と休暇申請・残数の連鎖 ----

    public function test_approval_confirms_the_usage_and_consumes_the_grant(): void
    {
        $this->grant($this->employee, 10.0);

        $requestId = $this->submitRequest($this->employee);
        $workflowRequestId = $this->workflowRequestIdOf($requestId);

        // 申請直後: 申請中・ワークフロー提出済み。消化記録は未確定で、残数は変わらない。
        $this->assertRequestStatus($requestId, 'submitted');
        $this->assertWorkflowStatus($workflowRequestId, 'submitted');
        $this->assertUsages($requestId, active: 1, confirmed: 0);
        $this->assertRemainingDays($this->employee, 10.0);

        $this->actingAs($this->approver)->postJson("/api/paid-leave/requests/{$requestId}/approve")->assertOk();

        // 承認後: 申請・ワークフローは承認済み。消化記録が確定し、残数が1日減る。
        $this->assertRequestStatus($requestId, 'approved');
        $this->assertWorkflowStatus($workflowRequestId, 'approved');
        $this->assertUsages($requestId, active: 1, confirmed: 1);
        $this->assertRemainingDays($this->employee, 9.0);
        // 連鎖の各承認は1回だけ記録される(Reactor同士の往復で二重承認にならない)。
        $this->assertSame(1, $this->storedEventCount($workflowRequestId, 'workflow_request.approved'));
        $this->assertSame(1, $this->storedEventCount($requestId, 'paid_leave_request.approved'));
    }

    public function test_return_then_resubmit_then_approve_counts_the_usage_only_once(): void
    {
        $this->grant($this->employee, 10.0);

        $requestId = $this->submitRequest($this->employee);
        $workflowRequestId = $this->workflowRequestIdOf($requestId);

        $this->actingAs($this->approver)
            ->postJson("/api/paid-leave/requests/{$requestId}/return", ['comment' => '日程を確認してください'])
            ->assertOk();

        // 差戻し: 申請は差戻し中。未確定の消化記録は取り消され、残数は戻る(差戻し中は残高に含まれない)。
        $this->assertRequestStatus($requestId, 'returned');
        $this->assertWorkflowStatus($workflowRequestId, 'returned');
        $this->assertUsages($requestId, active: 0, confirmed: 0);
        $this->assertRemainingDays($this->employee, 10.0);

        // 申請者は同じ内容のまま「提出する」で再提出する。
        $this->actingAs($this->employee)->postJson("/api/workflow-requests/{$workflowRequestId}/submit")->assertOk();

        // 再提出: 申請中に戻り、新しい消化記録は1件だけ(差し戻した分と二重に計上しない)。
        $this->assertRequestStatus($requestId, 'submitted');
        $this->assertWorkflowStatus($workflowRequestId, 'submitted');
        $this->assertUsages($requestId, active: 1, confirmed: 0);
        $this->assertRemainingDays($this->employee, 10.0);

        $this->actingAs($this->approver)->postJson("/api/paid-leave/requests/{$requestId}/approve")->assertOk();

        $this->assertRequestStatus($requestId, 'approved');
        $this->assertWorkflowStatus($workflowRequestId, 'approved');
        $this->assertUsages($requestId, active: 1, confirmed: 1);
        $this->assertRemainingDays($this->employee, 9.0);
        // 差戻しで取り消された旧い消化記録は、取消済みの行として残る(行は削除しない)。
        $this->assertSame(2, PaidLeaveUsage::query()->where('paid_leave_request_id', $requestId)->count());
    }

    public function test_employee_cancels_a_submitted_request_and_the_workflow_is_cancelled(): void
    {
        $this->grant($this->employee, 10.0);

        $requestId = $this->submitRequest($this->employee);
        $workflowRequestId = $this->workflowRequestIdOf($requestId);

        $this->actingAs($this->employee)->postJson("/api/paid-leave/requests/{$requestId}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertRequestStatus($requestId, 'cancelled');
        $this->assertWorkflowStatus($workflowRequestId, 'cancelled');
        $this->assertUsages($requestId, active: 0, confirmed: 0);
        $this->assertRemainingDays($this->employee, 10.0);
    }

    public function test_employee_cancels_an_approved_request_restores_the_grant_and_keeps_the_workflow_approved(): void
    {
        $this->grant($this->employee, 10.0);

        $requestId = $this->submitRequest($this->employee);
        $workflowRequestId = $this->workflowRequestIdOf($requestId);
        $this->actingAs($this->approver)->postJson("/api/paid-leave/requests/{$requestId}/approve")->assertOk();
        $this->assertRemainingDays($this->employee, 9.0);

        $this->actingAs($this->employee)->postJson("/api/paid-leave/requests/{$requestId}/cancel")->assertOk();

        // 承認済みのワークフローは状態を変えない(承認の取消は作らない。設計原則13)。
        $this->assertRequestStatus($requestId, 'cancelled');
        $this->assertWorkflowStatus($workflowRequestId, 'approved');
        $this->assertUsages($requestId, active: 0, confirmed: 0);
        $this->assertRemainingDays($this->employee, 10.0);
    }

    public function test_admin_cancel_of_an_approved_request_restores_the_grant(): void
    {
        $this->grant($this->employee, 10.0);

        $requestId = $this->submitRequest($this->employee);
        $workflowRequestId = $this->workflowRequestIdOf($requestId);
        $this->actingAs($this->approver)->postJson("/api/paid-leave/requests/{$requestId}/approve")->assertOk();

        $this->actingAs($this->hrUser())->postJson("/api/paid-leave/requests/{$requestId}/admin-cancel")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertRequestStatus($requestId, 'cancelled');
        $this->assertWorkflowStatus($workflowRequestId, 'approved');
        $this->assertUsages($requestId, active: 0, confirmed: 0);
        $this->assertRemainingDays($this->employee, 10.0);
    }

    public function test_cancelling_the_workflow_from_the_workflow_side_cancels_the_paid_leave_request(): void
    {
        $this->grant($this->employee, 10.0);

        $requestId = $this->submitRequest($this->employee);
        $workflowRequestId = $this->workflowRequestIdOf($requestId);

        $this->actingAs($this->employee)
            ->postJson("/api/workflow-requests/{$workflowRequestId}/cancel", ['reason' => '予定が変わりました'])
            ->assertOk();

        $this->assertWorkflowStatus($workflowRequestId, 'cancelled');
        $this->assertRequestStatus($requestId, 'cancelled');
        $this->assertUsages($requestId, active: 0, confirmed: 0);
        $this->assertRemainingDays($this->employee, 10.0);
    }

    public function test_cancelling_the_workflow_while_returned_cancels_the_paid_leave_request(): void
    {
        $this->grant($this->employee, 10.0);

        $requestId = $this->submitRequest($this->employee);
        $workflowRequestId = $this->workflowRequestIdOf($requestId);
        $this->actingAs($this->approver)
            ->postJson("/api/paid-leave/requests/{$requestId}/return", ['comment' => '差戻し'])
            ->assertOk();

        $this->actingAs($this->employee)
            ->postJson("/api/workflow-requests/{$workflowRequestId}/cancel", ['reason' => '取りやめ'])
            ->assertOk();

        $this->assertWorkflowStatus($workflowRequestId, 'cancelled');
        $this->assertRequestStatus($requestId, 'cancelled');
        $this->assertUsages($requestId, active: 0, confirmed: 0);
        $this->assertRemainingDays($this->employee, 10.0);
    }

    public function test_approval_not_required_request_is_approved_at_once_without_a_workflow(): void
    {
        SystemSetting::current()->update(['paid_leave_requires_approval' => false]);
        $this->grant($this->employee, 10.0);

        $response = $this->actingAs($this->employee)->postJson('/api/paid-leave/requests', [
            'target_date' => self::DATE,
            'leave_type' => 'full',
        ]);
        $response->assertCreated()->assertJsonPath('status', 'approved');
        $requestId = $response->json('id');

        $this->assertSame(0, WorkflowRequest::query()->where('subject_id', $requestId)->count());
        $this->assertUsages($requestId, active: 1, confirmed: 1);
        $this->assertRemainingDays($this->employee, 9.0);
    }

    public function test_approving_one_request_of_a_group_approves_the_rest_of_the_period_and_their_workflows(): void
    {
        $this->grant($this->employee, 10.0);
        $groupId = (string) Str::uuid();

        $requestIds = [];
        $workflowRequestIds = [];
        foreach ([self::DATE, '2026-08-11', '2026-08-12'] as $date) {
            $requestId = $this->submitRequest($this->employee, ['target_date' => $date, 'request_group_id' => $groupId]);
            $requestIds[] = $requestId;
            $workflowRequestIds[] = $this->workflowRequestIdOf($requestId);
        }

        $this->actingAs($this->approver)->postJson("/api/paid-leave/requests/{$requestIds[0]}/approve")->assertOk();

        foreach ($requestIds as $i => $requestId) {
            $this->assertRequestStatus($requestId, 'approved');
            $this->assertWorkflowStatus($workflowRequestIds[$i], 'approved');
            $this->assertUsages($requestId, active: 1, confirmed: 1);
            $this->assertSame(1, $this->storedEventCount($workflowRequestIds[$i], 'workflow_request.approved'));
            $this->assertSame(1, $this->storedEventCount($requestId, 'paid_leave_request.approved'));
        }
        $this->assertRemainingDays($this->employee, 7.0);
    }

    public function test_group_approval_skips_a_sibling_that_is_returned(): void
    {
        $this->grant($this->employee, 10.0);
        $groupId = (string) Str::uuid();

        $requestIds = [];
        $workflowRequestIds = [];
        foreach ([self::DATE, '2026-08-11', '2026-08-12'] as $date) {
            $requestId = $this->submitRequest($this->employee, ['target_date' => $date, 'request_group_id' => $groupId]);
            $requestIds[] = $requestId;
            $workflowRequestIds[] = $this->workflowRequestIdOf($requestId);
        }

        // 2日目だけを差し戻す。
        $this->actingAs($this->approver)
            ->postJson("/api/paid-leave/requests/{$requestIds[1]}/return", ['comment' => '2日目を見直してください'])
            ->assertOk();

        $this->actingAs($this->approver)->postJson("/api/paid-leave/requests/{$requestIds[0]}/approve")->assertOk();

        // 差戻し中の兄弟は承認されない。それ以外の申請中の兄弟は承認される。
        $this->assertRequestStatus($requestIds[0], 'approved');
        $this->assertRequestStatus($requestIds[1], 'returned');
        $this->assertRequestStatus($requestIds[2], 'approved');
        $this->assertWorkflowStatus($workflowRequestIds[1], 'returned');
        $this->assertWorkflowStatus($workflowRequestIds[2], 'approved');
        $this->assertUsages($requestIds[1], active: 0, confirmed: 0);
        $this->assertRemainingDays($this->employee, 8.0);
    }

    public function test_processing_the_same_approval_twice_does_not_change_any_context(): void
    {
        $this->grant($this->employee, 10.0);

        $requestId = $this->submitRequest($this->employee);
        $workflowRequestId = $this->workflowRequestIdOf($requestId);
        $this->actingAs($this->approver)->postJson("/api/paid-leave/requests/{$requestId}/approve")->assertOk();

        $storedEventCount = DB::table('stored_events')->count();

        // 同じ連鎖を再度(Reactor経由の二重処理を想定して)発行しても、どの文脈の状態も変わらない。
        $commandBus = app(CommandBus::class);
        $commandBus->dispatch(new ApproveWorkflowRequest(
            workflowRequestId: $workflowRequestId,
            approvedByUserId: $this->approver->id,
            viaReactor: true,
            initiatedByUserId: $this->approver->id,
        ));
        $commandBus->dispatch(new ApprovePaidLeaveRequest(
            paidLeaveRequestId: $requestId,
            approvedByUserId: $this->approver->id,
            viaReactor: true,
            initiatedByUserId: $this->approver->id,
        ));
        $commandBus->dispatch(new RequestPaidLeave(
            requestId: $requestId,
            userId: $this->employee->id,
            targetDate: self::DATE,
            leaveType: 'full',
            hours: null,
            approverUserId: $this->approver->id,
            reason: null,
            workflowRequestId: $workflowRequestId,
            viaReactor: true,
            initiatedByUserId: $this->employee->id,
        ));

        $this->assertSame($storedEventCount, DB::table('stored_events')->count());
        $this->assertRequestStatus($requestId, 'approved');
        $this->assertWorkflowStatus($workflowRequestId, 'approved');
        $this->assertUsages($requestId, active: 1, confirmed: 1);
        $this->assertRemainingDays($this->employee, 9.0);
    }

    public function test_processing_the_same_cancellation_twice_does_not_change_any_context(): void
    {
        $this->grant($this->employee, 10.0);

        $requestId = $this->submitRequest($this->employee);
        $workflowRequestId = $this->workflowRequestIdOf($requestId);
        $this->actingAs($this->employee)->postJson("/api/paid-leave/requests/{$requestId}/cancel")->assertOk();

        $storedEventCount = DB::table('stored_events')->count();

        app(CommandBus::class)->dispatch(new CancelPaidLeaveRequest(
            paidLeaveRequestId: $requestId,
            cancelledByUserId: $this->employee->id,
            viaReactor: true,
            initiatedByUserId: $this->employee->id,
        ));

        $this->assertSame($storedEventCount, DB::table('stored_events')->count());
        $this->assertRequestStatus($requestId, 'cancelled');
        $this->assertWorkflowStatus($workflowRequestId, 'cancelled');
        $this->assertUsages($requestId, active: 0, confirmed: 0);
        $this->assertRemainingDays($this->employee, 10.0);
    }

    public function test_approval_is_accepted_with_insufficient_balance_and_consumes_only_what_is_available(): void
    {
        $this->grant($this->employee, 0.5);

        $requestId = $this->submitRequest($this->employee);
        $workflowRequestId = $this->workflowRequestIdOf($requestId);

        $this->actingAs($this->approver)->postJson("/api/paid-leave/requests/{$requestId}/approve")->assertOk();

        // 残数不足でも承認は拒否しない(論点17)。充当できた0.5日だけ消化し、残りは未充当のまま確定する。
        $this->assertRequestStatus($requestId, 'approved');
        $this->assertWorkflowStatus($workflowRequestId, 'approved');
        $this->assertUsages($requestId, active: 1, confirmed: 1);
        $this->assertRemainingDays($this->employee, 0.0);
        $this->assertEqualsWithDelta(0.5, (float) PaidLeaveGrant::query()->where('user_id', $this->employee->id)->value('used_days'), 0.0001);
    }

    // ---- ヘルパー ----

    private function createWorkingDays(User $user, array $dates): void
    {
        $calendar = CompanyCalendar::query()->create(['name' => '2026年度', 'week_starts_on' => 1]);
        $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);
        $workStyle = WorkStyle::query()->create([
            'code' => 'standard-'.$user->id, 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);

        foreach ($dates as $date) {
            EmployeeCalendarEntry::query()->create([
                'user_id' => $user->id, 'work_date' => $date, 'work_style_id' => $workStyle->id,
                'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
                'planned_start_at' => "{$date} 09:00:00", 'planned_end_at' => "{$date} 18:00:00",
                'planned_break_minutes' => 60,
            ]);
        }
    }

    private function grant(User $user, float $days): void
    {
        app(CommandBus::class)->dispatch(new GrantPaidLeave($user->id, '2025-07-01', '2027-06-30', $days, null));
    }

    /** 全休の有給を申請し、申請IDを返す(承認者は$this->approver。$overridesで日付・まとめ申請IDを変える)。 */
    private function submitRequest(User $user, array $overrides = []): string
    {
        return $this->actingAs($user)->postJson('/api/paid-leave/requests', array_merge([
            'target_date' => self::DATE,
            'leave_type' => 'full',
            'approver_user_id' => $this->approver->id,
            'reason' => '私用のため',
        ], $overrides))->assertCreated()->json('id');
    }

    /** 集約(ワークフロー・有給申請)に記録された、指定イベントの件数。 */
    private function storedEventCount(string $aggregateUuid, string $eventClass): int
    {
        return DB::table('stored_events')
            ->where('aggregate_uuid', $aggregateUuid)
            ->where('event_class', $eventClass)
            ->count();
    }

    private function workflowRequestIdOf(string $requestId): string
    {
        return (string) WorkflowRequest::query()
            ->where('subject_type', 'paid_leave_request')
            ->where('subject_id', $requestId)
            ->value('id');
    }

    private function hrUser(): User
    {
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));

        return $hr;
    }

    private function assertRequestStatus(string $requestId, string $status): void
    {
        $this->assertSame($status, PaidLeaveRequest::query()->findOrFail($requestId)->status, 'paid leave request status');
    }

    private function assertWorkflowStatus(string $workflowRequestId, string $status): void
    {
        $this->assertSame($status, WorkflowRequest::query()->findOrFail($workflowRequestId)->status, 'workflow request status');
    }

    /** 取消されていない消化記録の件数(active)と、そのうち確定済みの件数(confirmed)。 */
    private function assertUsages(string $requestId, int $active, int $confirmed): void
    {
        $usages = PaidLeaveUsage::query()->where('paid_leave_request_id', $requestId)->where('cancelled', false);

        $this->assertSame($active, (clone $usages)->count(), 'active usages');
        $this->assertSame($confirmed, (clone $usages)->where('is_confirmed', true)->count(), 'confirmed usages');
    }

    private function assertRemainingDays(User $user, float $expected): void
    {
        $this->assertEqualsWithDelta(
            $expected,
            (float) PaidLeaveGrant::query()->where('user_id', $user->id)->sum('remaining_days'),
            0.0001,
        );
    }
}
