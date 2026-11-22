<?php

namespace Tests\Feature\BackOffice;

use App\Models\BackOfficeTask;
use App\Models\BackOfficeTaskStatus;
use App\Models\ExpenseClaim;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 詳細系レスポンス(show・assign・changeStatus)の allowed_next_statuses。
 * 現在の状態を除き、遷移ルール(BackOfficeTaskTransitionPolicy)で移れるステータスの一覧。
 */
class BackOfficeTaskAllowedStatusesTest extends TestCase
{
    use RefreshDatabase;

    private function makeStaff(): User
    {
        $staff = User::factory()->create();
        $this->assignRole($staff, Role::query()->create(['code' => Role::ACCOUNTING_STAFF, 'name' => '経理担当者']));

        return $staff;
    }

    /** BackOfficeTaskStatusTransitionTest::makeTaskWithTransitions と同じ作り方(発生源はWorkflowRequest)。 */
    private function makeTaskWithTransitions(?array $transitions, string $initialStatus = 'not_started', string $code = 'business_card'): BackOfficeTask
    {
        $applicant = User::factory()->create();
        $requestType = RequestType::query()->create([
            'code' => $code, 'name' => '名刺申請', 'form_schema' => [], 'is_active' => true,
            'requires_backoffice_task' => true, 'backoffice_task_type' => 'business_card',
            'allowed_status_transitions' => $transitions,
        ]);
        $workflowRequest = WorkflowRequest::query()->create([
            'request_type_id' => $requestType->id, 'title' => 'タクシー代', 'applicant_user_id' => $applicant->id,
            'status' => 'approved', 'form_data' => ['amount' => 1000],
        ]);

        return BackOfficeTask::query()->create([
            'source_type' => 'workflow_request', 'source_id' => $workflowRequest->id,
            'task_type' => 'business_card', 'title' => '名刺申請: 名刺100枚', 'status' => $initialStatus,
        ]);
    }

    /** WorkflowRequest以外(ExpenseClaim)が発生源のタスク。申請種別を持たないため制限なし。 */
    private function makeExpenseTask(string $initialStatus = 'not_started'): BackOfficeTask
    {
        $applicant = User::factory()->create();
        $claim = ExpenseClaim::query()->create([
            'employee_id' => $applicant->id, 'period_from' => '2020-01-01', 'period_to' => '2020-01-31',
            'status' => 'approved', 'total_amount' => 3400,
        ]);

        return BackOfficeTask::query()->create([
            'source_type' => 'expense_claim', 'source_id' => $claim->id,
            'task_type' => 'expense_reimbursement', 'title' => '経費精算: 申請者', 'status' => $initialStatus,
        ]);
    }

    /** @return array<int, string> 現在の状態を除く全ステータス(BackOfficeTaskStatus::all()の順)。 */
    private function allStatusesExcept(string $current): array
    {
        return array_values(array_filter(
            BackOfficeTaskStatus::all(),
            fn (string $status): bool => $status !== $current,
        ));
    }

    public function test_show_lists_only_the_statuses_the_transition_master_allows(): void
    {
        $staff = $this->makeStaff();
        $task = $this->makeTaskWithTransitions([
            'not_started' => ['in_review', 'cancelled'],
        ]);

        $response = $this->actingAs($staff)->getJson("/api/backoffice-tasks/{$task->id}")->assertOk();

        $this->assertSame(['in_review', 'cancelled'], $response->json('allowed_next_statuses'));
    }

    public function test_show_lists_all_other_statuses_when_the_request_type_has_no_master(): void
    {
        $staff = $this->makeStaff();
        $task = $this->makeTaskWithTransitions(null);

        $response = $this->actingAs($staff)->getJson("/api/backoffice-tasks/{$task->id}")->assertOk();

        $this->assertSame($this->allStatusesExcept('not_started'), $response->json('allowed_next_statuses'));
        $this->assertCount(8, $response->json('allowed_next_statuses'));
        $this->assertNotContains('not_started', $response->json('allowed_next_statuses'));
    }

    public function test_show_lists_all_other_statuses_for_a_task_whose_source_is_not_a_workflow_request(): void
    {
        $staff = $this->makeStaff();
        $task = $this->makeExpenseTask();

        $response = $this->actingAs($staff)->getJson("/api/backoffice-tasks/{$task->id}")->assertOk();

        $this->assertSame($this->allStatusesExcept('not_started'), $response->json('allowed_next_statuses'));
    }

    public function test_show_follows_the_transition_master_after_completion(): void
    {
        $staff = $this->makeStaff();
        $unrestricted = $this->makeTaskWithTransitions(null, 'completed', 'business_card_a');
        $restricted = $this->makeTaskWithTransitions(['completed' => ['cancelled']], 'completed', 'business_card_b');

        $this->actingAs($staff)->getJson("/api/backoffice-tasks/{$unrestricted->id}")
            ->assertOk()
            ->assertJsonPath('allowed_next_statuses', $this->allStatusesExcept('completed'));

        $this->actingAs($staff)->getJson("/api/backoffice-tasks/{$restricted->id}")
            ->assertOk()
            ->assertJsonPath('allowed_next_statuses', ['cancelled']);
    }

    public function test_change_status_response_lists_the_next_statuses_from_the_new_status(): void
    {
        $staff = $this->makeStaff();
        $task = $this->makeTaskWithTransitions([
            'not_started' => ['in_review', 'cancelled'],
            'in_review' => ['processing', 'cancelled'],
        ]);

        $response = $this->actingAs($staff)->postJson("/api/backoffice-tasks/{$task->id}/status", [
            'status' => 'in_review',
        ])->assertOk()->assertJsonPath('status', 'in_review');

        $this->assertSame(['processing', 'cancelled'], $response->json('allowed_next_statuses'));
    }

    public function test_assign_response_lists_the_next_statuses_after_the_automatic_move_to_in_review(): void
    {
        $staff = $this->makeStaff();
        $task = $this->makeTaskWithTransitions([
            'not_started' => ['in_review', 'cancelled'],
            'in_review' => ['processing', 'cancelled'],
        ]);

        $response = $this->actingAs($staff)->postJson("/api/backoffice-tasks/{$task->id}/assign", [
            'assigned_user_id' => $staff->id,
        ])->assertOk()->assertJsonPath('status', 'in_review');

        $this->assertSame(['processing', 'cancelled'], $response->json('allowed_next_statuses'));
    }

    public function test_index_and_bulk_complete_responses_do_not_include_allowed_next_statuses(): void
    {
        $staff = $this->makeStaff();
        $task = $this->makeTaskWithTransitions(null);
        $this->actingAs($staff)->postJson("/api/backoffice-tasks/{$task->id}/assign", [
            'assigned_user_id' => $staff->id,
        ])->assertOk();

        $index = $this->actingAs($staff)->getJson('/api/backoffice-tasks')->assertOk();
        $this->assertNotEmpty($index->json('data'));
        foreach ($index->json('data') as $item) {
            $this->assertArrayNotHasKey('allowed_next_statuses', $item);
        }

        $bulk = $this->actingAs($staff)->postJson('/api/backoffice-tasks/bulk-complete', [
            'task_ids' => [$task->id],
        ])->assertOk();
        $this->assertNotEmpty($bulk->json());
        foreach ($bulk->json() as $item) {
            $this->assertArrayNotHasKey('allowed_next_statuses', $item);
        }
    }
}
