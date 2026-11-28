<?php

namespace Tests\Feature\Workflow;

use App\Models\CompanyCalendar;
use App\Models\EmployeeCalendarEntry;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\ShiftSwapRequest;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 申請一覧からワークフローを取り消したとき(単件・一括)、経費・振替休日の業務側も取り消される
 * (ワークフローの取消を受けるReactor。viaReactorで冪等)。逆向きの連鎖(業務側の取消→ワークフローの取消)と往復しない。
 */
class WorkflowRequestCancelPropagationTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = User::factory()->create();
        $this->approver = User::factory()->create();
    }

    private function submitExpenseClaim(): string
    {
        $category = ExpenseCategory::query()->firstOrCreate(['code' => 'transportation'], [
            'name' => '交通費', 'evidence_type_default' => ExpenseCategory::EVIDENCE_FACT_REFERENCE_AVAILABLE,
        ]);
        $claimId = $this->actingAs($this->employee)->postJson('/api/expense-claims')->json('id');
        $this->actingAs($this->employee)->postJson("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'amount' => 500,
        ])->assertCreated();
        $this->actingAs($this->employee)->postJson("/api/expense-claims/{$claimId}/submit", [
            'approver_user_id' => $this->approver->id,
        ])->assertOk();

        return $claimId;
    }

    private function submitShiftSwap(string $targetDate = '2026-08-16', string $substituteDate = '2026-08-12'): string
    {
        if (! WorkStyle::query()->exists()) {
            $calendar = CompanyCalendar::query()->create(['name' => '2026年度', 'week_starts_on' => 1]);
            $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);
            $workStyle = WorkStyle::query()->create([
                'code' => 'fixed-1', 'name' => '固定勤務', 'work_time_system' => 'fixed',
                'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
                'default_start_time' => '09:00', 'default_end_time' => '18:00',
                'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
                'legal_holiday_rule' => WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY,
            ]);
            foreach (['2026-08-10', '2026-08-11', '2026-08-12', '2026-08-13', '2026-08-14'] as $date) {
                EmployeeCalendarEntry::query()->create([
                    'user_id' => $this->employee->id, 'work_date' => $date, 'work_style_id' => $workStyle->id,
                    'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
                    'planned_start_at' => "{$date} 09:00:00", 'planned_end_at' => "{$date} 15:40:00", 'planned_break_minutes' => 0,
                ]);
            }
            EmployeeCalendarEntry::query()->create([
                'user_id' => $this->employee->id, 'work_date' => '2026-08-16', 'work_style_id' => $workStyle->id,
                'day_type' => 'legal_holiday', 'is_working_day' => false, 'is_legal_holiday' => true, 'is_company_holiday' => false,
                'planned_start_at' => null, 'planned_end_at' => null, 'planned_break_minutes' => 0,
            ]);
        }

        return $this->actingAs($this->employee)->postJson('/api/shift-swap/requests/workflow', [
            'target_date' => $targetDate, 'substitute_date' => $substituteDate, 'approver_user_id' => $this->approver->id,
        ])->assertCreated()->json('id');
    }

    private function workflowIdOf(string $subjectType, string $subjectId): string
    {
        return (string) WorkflowRequest::query()->where('subject_type', $subjectType)->where('subject_id', $subjectId)->value('id');
    }

    private function eventCount(string $aggregateUuid, string $eventClass): int
    {
        return DB::table('stored_events')->where('aggregate_uuid', $aggregateUuid)->where('event_class', $eventClass)->count();
    }

    public function test_cancelling_the_workflow_of_an_expense_claim_cancels_the_claim(): void
    {
        $claimId = $this->submitExpenseClaim();
        $workflowId = $this->workflowIdOf('expense_claim', $claimId);

        $this->actingAs($this->employee)->postJson("/api/workflow-requests/{$workflowId}/cancel", ['reason' => '不要'])->assertOk();

        $this->assertSame('cancelled', ExpenseClaim::query()->findOrFail($claimId)->status);
        $this->assertSame('cancelled', WorkflowRequest::query()->findOrFail($workflowId)->status);
        $this->assertSame(1, $this->eventCount($claimId, 'expense_claim.cancelled'));
        $this->assertSame(1, $this->eventCount($workflowId, 'workflow_request.cancelled'));
    }

    public function test_cancelling_the_workflow_of_a_shift_swap_request_cancels_the_request(): void
    {
        $swapId = $this->submitShiftSwap();
        $workflowId = $this->workflowIdOf('shift_swap_request', $swapId);

        $this->actingAs($this->employee)->postJson("/api/workflow-requests/{$workflowId}/cancel", ['reason' => '不要'])->assertOk();

        $this->assertSame('cancelled', ShiftSwapRequest::query()->findOrFail($swapId)->status);
        $this->assertSame('cancelled', WorkflowRequest::query()->findOrFail($workflowId)->status);
        $this->assertSame(1, $this->eventCount($swapId, 'shift_swap.request_cancelled'));
        $this->assertSame(1, $this->eventCount($workflowId, 'workflow_request.cancelled'));
    }

    public function test_bulk_cancel_cancels_expense_claims_and_shift_swap_requests_too(): void
    {
        $claimId = $this->submitExpenseClaim();
        $swapId = $this->submitShiftSwap();
        $claimWorkflowId = $this->workflowIdOf('expense_claim', $claimId);
        $swapWorkflowId = $this->workflowIdOf('shift_swap_request', $swapId);

        $this->actingAs($this->employee)->postJson('/api/workflow-requests/bulk-cancel', [
            'workflow_request_ids' => [$claimWorkflowId, $swapWorkflowId], 'reason' => 'まとめて取消',
        ])->assertOk()->assertJsonCount(2, 'data');

        $this->assertSame('cancelled', ExpenseClaim::query()->findOrFail($claimId)->status);
        $this->assertSame('cancelled', ShiftSwapRequest::query()->findOrFail($swapId)->status);
        $this->assertSame('cancelled', WorkflowRequest::query()->findOrFail($claimWorkflowId)->status);
        $this->assertSame('cancelled', WorkflowRequest::query()->findOrFail($swapWorkflowId)->status);
    }

    public function test_cancelling_from_the_business_side_still_cancels_the_workflow_once(): void
    {
        $claimId = $this->submitExpenseClaim();
        $workflowId = $this->workflowIdOf('expense_claim', $claimId);

        $this->actingAs($this->employee)->postJson("/api/expense-claims/{$claimId}/cancel", ['reason' => '不要'])->assertOk();

        $this->assertSame('cancelled', WorkflowRequest::query()->findOrFail($workflowId)->status);
        $this->assertSame(1, $this->eventCount($claimId, 'expense_claim.cancelled'));
        $this->assertSame(1, $this->eventCount($workflowId, 'workflow_request.cancelled'));
    }
}
