<?php

namespace Tests\Feature\CompensatoryLeave;

use App\Models\LeaveRequestWorkflowLink;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 代休申請の応答に、対応するワークフローID(workflow_request_id)が含まれることを確認する。
 * 値は休暇申請文脈の対応表(leave_request_workflow_links)から引く。
 */
class CompensatoryLeaveWorkflowRequestIdTest extends TestCase
{
    use CompensatoryLeaveTestHelpers;
    use RefreshDatabase;

    public function test_responses_include_the_workflow_request_id_of_the_request(): void
    {
        $this->enableCompensatoryLeave();
        $this->makeCompensatoryWorkStyle();
        $employee = User::factory()->create();
        $approver = User::factory()->create();

        $requestId = $this->requestCompensatoryLeave($employee, $approver, '2026-09-10');
        $workflowRequestId = LeaveRequestWorkflowLink::query()->where('leave_request_id', $requestId)->value('workflow_request_id');
        $this->assertNotNull($workflowRequestId);

        $this->actingAs($employee)->getJson('/api/compensatory-leave/requests/mine')
            ->assertOk()->assertJsonPath('0.workflow_request_id', $workflowRequestId);
        $this->actingAs($approver)->getJson('/api/compensatory-leave/requests/to-approve')
            ->assertOk()->assertJsonPath('0.workflow_request_id', $workflowRequestId);
        $this->actingAs($approver)->postJson("/api/compensatory-leave/requests/{$requestId}/approve")
            ->assertOk()->assertJsonPath('workflow_request_id', $workflowRequestId);
    }

    public function test_workflow_request_id_is_null_when_the_request_has_no_approval_workflow(): void
    {
        $this->enableCompensatoryLeave();
        $this->makeCompensatoryWorkStyle();
        $employee = User::factory()->create();
        SystemSetting::current()->update(['compensatory_leave_requires_approval' => false]);
        $this->makeCompensatoryWorkingDayShift($employee, WorkStyle::query()->firstOrFail(), '2026-09-10');

        $this->actingAs($employee)->postJson('/api/compensatory-leave/requests', [
            'target_date' => '2026-09-10',
            'leave_type' => 'full',
        ])->assertCreated()->assertJsonPath('workflow_request_id', null);

        $this->actingAs($employee)->getJson('/api/compensatory-leave/requests/mine')
            ->assertOk()->assertJsonPath('0.workflow_request_id', null);
    }
}
