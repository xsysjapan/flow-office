<?php

namespace Tests\Feature\Workflow;

use App\Domain\Workflow\Commands\ApproveWorkflowRequests;
use App\Domain\Workflow\Commands\CancelWorkflowRequests;
use App\Models\AttendanceMonth;
use App\Models\CompanyCalendar;
use App\Models\EmployeeCalendarEntry;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\PaidLeaveRequest;
use App\Models\RequestType;
use App\Models\ShiftSwapRequest;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AssertsSingleTopLevelCommand;
use Tests\TestCase;

/**
 * 申請の一括取消・承認の一括(`POST /workflow-requests/bulk-cancel`・`bulk-approve`)。
 * 全部か無しか: 1件でも失敗したら何も記録せず、422(code: bulk_operation_rejected)で失敗した対象と理由をすべて返す。
 * 業務側(休暇・振替休日・経費・月次勤怠)の承認・取消は、ワークフローのイベントを受けるReactorの連鎖で行われる。
 */
class WorkflowRequestBulkOperationsTest extends TestCase
{
    use AssertsSingleTopLevelCommand;
    use RefreshDatabase;

    private User $applicant;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->applicant = User::factory()->create();
        $this->approver = User::factory()->create();
    }

    /** 汎用申請(提出済み)を作り、ワークフローIDを返す。 */
    private function submitGeneric(?User $applicant = null, ?User $approver = null, string $title = '汎用申請'): string
    {
        $applicant ??= $this->applicant;
        $approver ??= $this->approver;
        RequestType::query()->firstOrCreate(['code' => 'general_request'], [
            'name' => '一般申請', 'form_schema' => [], 'requires_backoffice_task' => false, 'is_active' => true,
        ]);

        $id = $this->actingAs($applicant)->postJson('/api/workflow-requests', [
            'request_type_code' => 'general_request', 'title' => $title, 'form_data' => [],
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');
        $this->actingAs($applicant)->postJson("/api/workflow-requests/{$id}/submit")->assertOk();

        return $id;
    }

    private function storedEventCount(string $aggregateUuid, string $eventClass): int
    {
        return DB::table('stored_events')->where('aggregate_uuid', $aggregateUuid)->where('event_class', $eventClass)->count();
    }

    private function workflowStatus(string $workflowRequestId): string
    {
        return (string) WorkflowRequest::query()->whereKey($workflowRequestId)->value('status');
    }

    // ---- 申請の一括取消 ----

    public function test_bulk_cancel_cancels_every_request_with_a_single_top_level_command(): void
    {
        $ids = [$this->submitGeneric(title: 'A'), $this->submitGeneric(title: 'B')];

        $bus = $this->recordCommands();
        $response = $this->actingAs($this->applicant)->postJson('/api/workflow-requests/bulk-cancel', [
            'workflow_request_ids' => $ids, 'reason' => 'まとめて取消',
        ])->assertOk()->assertJsonCount(2, 'data');

        $this->assertSingleTopLevelCommand($bus, CancelWorkflowRequests::class);
        $this->assertEqualsCanonicalizing($ids, array_column($response->json('data'), 'id'));
        foreach ($ids as $id) {
            $this->assertSame('cancelled', $this->workflowStatus($id));
            $this->assertSame(1, $this->storedEventCount($id, 'workflow_request.cancelled'));
        }
    }

    public function test_bulk_cancel_records_nothing_when_one_fails_and_returns_every_failure(): void
    {
        $mine = $this->submitGeneric(title: '自分の申請');
        $strangerA = $this->submitGeneric(User::factory()->create(), title: '他人の申請A');
        $strangerB = $this->submitGeneric(User::factory()->create(), title: '他人の申請B');

        $eventsBefore = DB::table('stored_events')->count();

        $response = $this->actingAs($this->applicant)->postJson('/api/workflow-requests/bulk-cancel', [
            'workflow_request_ids' => [$mine, $strangerA, $strangerB], 'reason' => '取消',
        ])->assertUnprocessable()->assertJsonPath('code', 'bulk_operation_rejected');

        $errors = $response->json('errors');
        $this->assertSame(['自分が作成した申請のみ取り消せます。'], $errors["items.{$strangerA}"]);
        $this->assertSame(['自分が作成した申請のみ取り消せます。'], $errors["items.{$strangerB}"]);
        $this->assertArrayNotHasKey("items.{$mine}", $errors);
        $this->assertSame($eventsBefore, DB::table('stored_events')->count());
        $this->assertSame('submitted', $this->workflowStatus($mine));
    }

    public function test_bulk_cancel_rejects_more_than_100_and_duplicates(): void
    {
        $id = $this->submitGeneric();

        $this->actingAs($this->applicant)->postJson('/api/workflow-requests/bulk-cancel', [
            'workflow_request_ids' => array_fill(0, 101, (string) Str::uuid()), 'reason' => '取消',
        ])->assertUnprocessable()->assertJsonValidationErrors(['workflow_request_ids']);

        $this->actingAs($this->applicant)->postJson('/api/workflow-requests/bulk-cancel', [
            'workflow_request_ids' => [$id, $id], 'reason' => '取消',
        ])->assertUnprocessable()->assertJsonValidationErrors(['workflow_request_ids.0']);
        $this->assertSame('submitted', $this->workflowStatus($id));
    }

    // ---- 承認の一括 ----

    public function test_bulk_approve_approves_every_request_with_a_single_top_level_command(): void
    {
        $ids = [$this->submitGeneric(title: 'A'), $this->submitGeneric(title: 'B')];

        $bus = $this->recordCommands();
        $this->actingAs($this->approver)->postJson('/api/workflow-requests/bulk-approve', [
            'workflow_request_ids' => $ids,
        ])->assertOk()->assertJsonCount(2, 'data');

        $this->assertSingleTopLevelCommand($bus, ApproveWorkflowRequests::class);
        foreach ($ids as $id) {
            $this->assertSame('approved', $this->workflowStatus($id));
            $this->assertSame(1, $this->storedEventCount($id, 'workflow_request.approved'));
        }
    }

    public function test_bulk_approve_records_nothing_when_some_fail_and_returns_every_failure(): void
    {
        $ok = $this->submitGeneric(title: '指定どおり');
        $otherApprover = User::factory()->create();
        $wrongA = $this->submitGeneric(approver: $otherApprover, title: '別の承認者A');
        $wrongB = $this->submitGeneric(approver: $otherApprover, title: '別の承認者B');

        $eventsBefore = DB::table('stored_events')->count();

        $response = $this->actingAs($this->approver)->postJson('/api/workflow-requests/bulk-approve', [
            'workflow_request_ids' => [$ok, $wrongA, $wrongB],
        ])->assertUnprocessable()->assertJsonPath('code', 'bulk_operation_rejected');

        $errors = $response->json('errors');
        $this->assertSame(['指定された承認者のみ承認できます。'], $errors["items.{$wrongA}"]);
        $this->assertSame(['指定された承認者のみ承認できます。'], $errors["items.{$wrongB}"]);
        $this->assertCount(2, $errors);
        $this->assertSame($eventsBefore, DB::table('stored_events')->count());
        $this->assertSame('submitted', $this->workflowStatus($ok));
    }

    public function test_bulk_approve_rejects_more_than_100_and_duplicates(): void
    {
        $id = $this->submitGeneric();

        $this->actingAs($this->approver)->postJson('/api/workflow-requests/bulk-approve', [
            'workflow_request_ids' => array_fill(0, 101, (string) Str::uuid()),
        ])->assertUnprocessable()->assertJsonValidationErrors(['workflow_request_ids']);

        $this->actingAs($this->approver)->postJson('/api/workflow-requests/bulk-approve', [
            'workflow_request_ids' => [$id, $id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['workflow_request_ids.0']);
        $this->assertSame('submitted', $this->workflowStatus($id));
    }

    public function test_bulk_approve_with_an_already_approved_request_is_rejected_for_that_request(): void
    {
        $approved = $this->submitGeneric(title: '承認済み');
        $this->actingAs($this->approver)->postJson("/api/workflow-requests/{$approved}/approve")->assertOk();
        $pending = $this->submitGeneric(title: '未承認');

        $response = $this->actingAs($this->approver)->postJson('/api/workflow-requests/bulk-approve', [
            'workflow_request_ids' => [$approved, $pending],
        ])->assertUnprocessable();

        $this->assertSame(['提出済みの申請のみ承認できます。'], $response->json('errors')["items.{$approved}"]);
        $this->assertSame('submitted', $this->workflowStatus($pending));
    }

    /** まとめ申請の兄弟を同時に選ぶと、先の承認の連鎖で承認済みになった兄弟は飛ばされて全体が通る。 */
    public function test_bulk_approve_with_sibling_requests_of_one_group_passes(): void
    {
        $this->createWorkingDays($this->applicant, ['2026-08-10', '2026-08-11', '2026-08-12']);
        $this->grantPaidLeave($this->applicant, 10.0);
        $groupId = (string) Str::uuid();

        $workflowIds = [];
        foreach (['2026-08-10', '2026-08-11', '2026-08-12'] as $date) {
            $requestId = $this->actingAs($this->applicant)->postJson('/api/paid-leave/requests/workflow', [
                'target_date' => $date, 'leave_type' => 'full', 'approver_user_id' => $this->approver->id,
                'reason' => '私用', 'request_group_id' => $groupId,
            ])->assertCreated()->json('id');
            $workflowIds[] = $this->workflowIdOf('paid_leave_request', $requestId);
        }

        $response = $this->actingAs($this->approver)->postJson('/api/workflow-requests/bulk-approve', [
            'workflow_request_ids' => $workflowIds,
        ])->assertOk();

        $results = collect($response->json('data'))->pluck('result')->countBy()->all();
        $this->assertSame(['processed' => 1, 'skipped' => 2], $results);
        foreach ($workflowIds as $id) {
            $this->assertSame('approved', $this->workflowStatus($id));
            $this->assertSame(1, $this->storedEventCount($id, 'workflow_request.approved'));
        }
        $this->assertSame(3, PaidLeaveRequest::query()->where('status', 'approved')->count());
    }

    /** 種類の違う申請(休暇・振替休日・経費・月次勤怠・汎用)を混ぜて一括承認し、業務側まで承認される。 */
    public function test_bulk_approve_approves_mixed_request_types_down_to_the_business_side(): void
    {
        $employee = $this->applicant;
        $workStyle = $this->createWorkingDays($employee, ['2026-08-10', '2026-08-11', '2026-08-12', '2026-08-13', '2026-08-14']);
        $this->grantPaidLeave($employee, 10.0);
        EmployeeCalendarEntry::query()->create([
            'user_id' => $employee->id, 'work_date' => '2026-08-16', 'work_style_id' => $workStyle->id,
            'day_type' => 'legal_holiday', 'is_working_day' => false, 'is_legal_holiday' => true, 'is_company_holiday' => false,
            'planned_start_at' => null, 'planned_end_at' => null, 'planned_break_minutes' => 0,
        ]);

        // 休暇
        $leaveId = $this->actingAs($employee)->postJson('/api/paid-leave/requests/workflow', [
            'target_date' => '2026-08-10', 'leave_type' => 'full', 'approver_user_id' => $this->approver->id, 'reason' => '私用',
        ])->assertCreated()->json('id');
        // 振替休日
        $swapId = $this->actingAs($employee)->postJson('/api/shift-swap/requests/workflow', [
            'target_date' => '2026-08-16', 'substitute_date' => '2026-08-12', 'approver_user_id' => $this->approver->id,
        ])->assertCreated()->json('id');
        // 経費
        $claimId = $this->submitExpenseClaim($employee, $this->approver);
        // 月次勤怠
        $yearMonth = Carbon::today($employee->timezone)->format('Y-m');
        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();
        $this->actingAs($employee)->postJson("/api/attendance/months/{$yearMonth}/submit", [
            'approver_user_id' => $this->approver->id,
        ])->assertSuccessful();
        $monthId = AttendanceMonth::query()->where('user_id', $employee->id)->where('year_month', $yearMonth)->firstOrFail()->id;
        // 汎用
        $genericWorkflowId = $this->submitGeneric();

        $workflowIds = [
            $this->workflowIdOf('paid_leave_request', $leaveId),
            $this->workflowIdOf('shift_swap_request', $swapId),
            $this->workflowIdOf('expense_claim', $claimId),
            $this->workflowIdOf('attendance_month', $monthId),
            $genericWorkflowId,
        ];

        $bus = $this->recordCommands();
        $this->actingAs($this->approver)->postJson('/api/workflow-requests/bulk-approve', [
            'workflow_request_ids' => $workflowIds,
        ])->assertOk()->assertJsonCount(5, 'data');
        $this->assertSingleTopLevelCommand($bus, ApproveWorkflowRequests::class);

        foreach ($workflowIds as $id) {
            $this->assertSame('approved', $this->workflowStatus($id));
        }
        $this->assertSame('approved', PaidLeaveRequest::query()->findOrFail($leaveId)->status);
        $this->assertSame('approved', ShiftSwapRequest::query()->findOrFail($swapId)->status);
        $this->assertSame('approved', ExpenseClaim::query()->findOrFail($claimId)->status);
        $this->assertSame('approved', AttendanceMonth::query()->findOrFail($monthId)->status);
    }

    // ---- ヘルパー ----

    private function workflowIdOf(string $subjectType, string $subjectId): string
    {
        return (string) WorkflowRequest::query()->where('subject_type', $subjectType)->where('subject_id', $subjectId)->value('id');
    }

    private function submitExpenseClaim(User $employee, User $approver): string
    {
        $category = ExpenseCategory::query()->firstOrCreate(['code' => 'transportation'], [
            'name' => '交通費', 'evidence_type_default' => ExpenseCategory::EVIDENCE_FACT_REFERENCE_AVAILABLE,
        ]);
        $claimId = $this->actingAs($employee)->postJson('/api/expense-claims')->json('id');
        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'amount' => 500,
        ])->assertCreated();
        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertOk();

        return $claimId;
    }

    private function grantPaidLeave(User $user, float $days): void
    {
        app(\App\Domain\EventSourcing\CommandBus::class)->dispatch(
            new \App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave($user->id, '2025-07-01', '2027-06-30', $days, null)
        );
    }

    /** @param list<string> $dates */
    private function createWorkingDays(User $user, array $dates): WorkStyle
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
                'planned_start_at' => "{$date} 09:00:00", 'planned_end_at' => "{$date} 15:40:00",
                'planned_break_minutes' => 0,
            ]);
        }

        return $workStyle;
    }
}
