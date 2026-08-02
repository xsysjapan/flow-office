<?php

namespace Tests\Feature\Workflow;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\Workflow\Commands\BackfillAttendanceMonthWorkflowRequest;
use App\Models\AttendanceMonth;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WorkflowRequestによる月次勤怠申請のラップ導入前に提出済みだった月次勤怠に対する
 * 事後的なworkflow_requestの補完(BackfillAttendanceMonthWorkflowRequestHandler)。
 */
class BackfillAttendanceMonthWorkflowRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfills_workflow_request_for_a_submitted_month_missing_one(): void
    {
        $applicant = User::factory()->create();
        $approver = User::factory()->create();
        $month = AttendanceMonth::query()->create([
            'user_id' => $applicant->id,
            'year_month' => '2026-07',
            'status' => 'submitted',
            'approver_user_id' => $approver->id,
            'submitted_at' => '2026-07-31 00:11:43',
        ]);

        $count = app(CommandBus::class)->dispatch(new BackfillAttendanceMonthWorkflowRequest);

        $this->assertSame(1, $count);

        $workflowRequest = WorkflowRequest::query()
            ->where('subject_type', 'attendance_month')
            ->where('subject_id', $month->id)
            ->first();

        $this->assertNotNull($workflowRequest);
        $this->assertSame(WorkflowRequestStatus::SUBMITTED, $workflowRequest->status);
        $this->assertSame($applicant->id, $workflowRequest->applicant_user_id);
        $this->assertSame($approver->id, $workflowRequest->approver_user_id);
        $this->assertSame('2026-07 月次勤怠', $workflowRequest->title);
        $this->assertSame('2026-07-31 00:11:43', $workflowRequest->submitted_at->format('Y-m-d H:i:s'));

        // Reactorを発火させず、通常と同じ実イベントのみをstored_eventsへ記録していること。
        $storedEventTypes = DB::table('stored_events')
            ->where('aggregate_uuid', $workflowRequest->id)
            ->orderBy('id')
            ->pluck('event_class')
            ->all();
        $this->assertSame(['workflow_request.drafted', 'workflow_request.submitted'], $storedEventTypes);
    }

    public function test_does_not_duplicate_workflow_request_for_a_month_that_already_has_one(): void
    {
        $applicant = User::factory()->create();
        $approver = User::factory()->create();
        $month = AttendanceMonth::query()->create([
            'user_id' => $applicant->id,
            'year_month' => '2026-07',
            'status' => 'submitted',
            'approver_user_id' => $approver->id,
            'submitted_at' => '2026-07-31 00:11:43',
        ]);

        WorkflowRequest::query()->create([
            'title' => '2026-07 月次勤怠',
            'applicant_user_id' => $applicant->id,
            'approver_user_id' => $approver->id,
            'status' => WorkflowRequestStatus::SUBMITTED,
            'form_data' => [],
            'subject_type' => 'attendance_month',
            'subject_id' => $month->id,
            'submitted_at' => '2026-07-31 00:11:43',
        ]);

        $count = app(CommandBus::class)->dispatch(new BackfillAttendanceMonthWorkflowRequest);

        $this->assertSame(0, $count);
        $this->assertSame(1, WorkflowRequest::query()->where('subject_type', 'attendance_month')->where('subject_id', $month->id)->count());
    }

    public function test_ignores_months_that_are_not_submitted(): void
    {
        $applicant = User::factory()->create();
        AttendanceMonth::query()->create([
            'user_id' => $applicant->id,
            'year_month' => '2026-07',
            'status' => 'not_submitted',
        ]);

        $count = app(CommandBus::class)->dispatch(new BackfillAttendanceMonthWorkflowRequest);

        $this->assertSame(0, $count);
        $this->assertSame(0, WorkflowRequest::query()->count());
    }
}

