<?php

namespace Tests\Feature\Workflow;

use App\Models\AttendanceMonth;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Models\WorkflowRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * changeset 20260829-backoffice-task-detail-cleanup 論点6:
 * 月次勤怠申請(subject_type = 'attendance_month')の「取消」は、申請者本人であることに加えて
 * 専用権限`attendance.submission_revoke`(selfスコープ)を持つことを要求する。他のsubject_type
 * (経費精算等)は従来通り申請者本人であれば無条件に取消できる(非破壊確認)。
 */
class AttendanceMonthCancelPermissionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: WorkflowRequest, 1: string} */
    private function submitAttendanceMonthRequest(User $employee, User $approver, string $yearMonth): array
    {
        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();

        $monthId = $this->actingAs($employee)->postJson("/api/attendance/months/{$yearMonth}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful()->json('id');

        $request = WorkflowRequest::query()
            ->where('subject_type', 'attendance_month')
            ->where('subject_id', $monthId)
            ->latest('created_at')
            ->firstOrFail();

        return [$request, $monthId];
    }

    public function test_applicant_with_permission_can_cancel_an_attendance_month_request(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $yearMonth = Carbon::today($employee->timezone)->format('Y-m');

        $this->grantSelfPermission($employee, 'attendance.submission_revoke');

        [$workflowRequest, $monthId] = $this->submitAttendanceMonthRequest($employee, $approver, $yearMonth);

        $this->actingAs($employee)->postJson("/api/workflow-requests/{$workflowRequest->id}/cancel", [
            'reason' => '内容を見直したいので取り消します',
        ])->assertOk()->assertJsonPath('status', 'cancelled');

        $this->assertSame('not_submitted', AttendanceMonth::query()->findOrFail($monthId)->status);
    }

    public function test_applicant_without_permission_cannot_cancel_an_attendance_month_request(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $yearMonth = Carbon::today($employee->timezone)->format('Y-m');

        [$workflowRequest, $monthId] = $this->submitAttendanceMonthRequest($employee, $approver, $yearMonth);

        $this->actingAs($employee)->postJson("/api/workflow-requests/{$workflowRequest->id}/cancel", [
            'reason' => '内容を見直したいので取り消します',
        ])->assertStatus(422);

        $workflowRequest->refresh();
        $this->assertSame('submitted', $workflowRequest->status);
        $this->assertSame('submitted', AttendanceMonth::query()->findOrFail($monthId)->status);
    }

    /**
     * 他のsubject_type(経費精算)は`attendance.submission_revoke`権限の有無に関わらず、
     * 申請者本人であれば従来通り取消できる(既存動作が壊れていないことの非破壊確認)。
     */
    public function test_expense_claim_requests_remain_cancellable_by_the_applicant_without_the_new_permission(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();

        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費',
            'evidence_type_default' => ExpenseCategory::EVIDENCE_FACT_REFERENCE_AVAILABLE,
        ]);

        $claimId = $this->actingAs($employee)->postJson('/api/expense-claims')->json('id');
        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'description' => '自宅 → 会社(電車)',
            'amount' => 500, 'usage_date' => '2026-07-01',
        ])->assertCreated();
        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertOk();

        $workflowRequest = WorkflowRequest::query()
            ->where('subject_type', 'expense_claim')
            ->where('subject_id', $claimId)
            ->firstOrFail();

        // このユーザーはattendance.submission_revokeを一切持たない。
        $this->actingAs($employee)->postJson("/api/workflow-requests/{$workflowRequest->id}/cancel", [
            'reason' => '内容を見直したいので取り消します',
        ])->assertOk()->assertJsonPath('status', 'cancelled');
    }
}
