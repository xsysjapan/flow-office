<?php

namespace Tests\Feature\BackOffice;

use App\Models\BackOfficeTask;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * UC-B002/UC-B003: 担当者割当・処理ステータス更新。承認とは別ステータス系列であること。
 */
class BackOfficeTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_assign_and_change_status(): void
    {
        $staff = User::factory()->create();
        $this->assignRole($staff, Role::query()->create(['code' => Role::ACCOUNTING_STAFF, 'name' => '経理担当者']));

        $task = BackOfficeTask::query()->create([
            'source_type' => 'workflow_request',
            'source_id' => 1,
            'task_type' => 'business_card',
            'title' => 'テストタスク',
            'status' => 'not_started',
        ]);

        $this->actingAs($staff)->postJson("/api/backoffice-tasks/{$task->id}/assign", [
            'assigned_user_id' => $staff->id,
        ])->assertOk()->assertJsonPath('status', 'in_review');

        $this->actingAs($staff)->postJson("/api/backoffice-tasks/{$task->id}/status", [
            'status' => 'payment_scheduled',
        ])->assertOk()->assertJsonPath('status', 'payment_scheduled');

        $this->actingAs($staff)->postJson("/api/backoffice-tasks/{$task->id}/status", [
            'status' => 'completed',
        ])->assertOk()->assertJsonPath('status', 'completed');

        $this->assertDatabaseHas('backoffice_tasks', ['id' => $task->id, 'status' => 'completed']);
    }

    public function test_employee_without_backoffice_role_cannot_access(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->getJson('/api/backoffice-tasks')->assertForbidden();
    }

    public function test_task_lists_can_be_searched_and_paginated(): void
    {
        $staff = User::factory()->create();
        $this->assignRole($staff, Role::query()->create(['code' => Role::ACCOUNTING_STAFF, 'name' => '経理担当者']));

        foreach (range(1, 5) as $index) {
            BackOfficeTask::query()->create([
                'source_type' => 'workflow_request',
                'source_id' => $index,
                'task_type' => 'expense_reimbursement',
                'title' => "検索対象タスク{$index}",
                'status' => 'not_started',
            ]);
        }
        BackOfficeTask::query()->create([
            'source_type' => 'workflow_request',
            'source_id' => 99,
            'task_type' => 'business_card',
            'title' => '別のタスク',
            'status' => 'not_started',
        ]);

        $response = $this->actingAs($staff)->getJson('/api/backoffice-tasks?search=検索対象&per_page=2&page=2');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.assignee_counts.unassigned', 5)
            ->assertJsonPath('meta.assignee_counts.all', 5);
    }

    public function test_assignee_can_bulk_complete_own_tasks(): void
    {
        $staff = User::factory()->create();
        $other = User::factory()->create();
        $this->assignRole($staff, Role::query()->create(['code' => Role::ACCOUNTING_STAFF, 'name' => '経理担当者']));

        $tasks = collect(range(1, 2))->map(fn (int $index) => BackOfficeTask::query()->create([
            'source_type' => 'workflow_request',
            'source_id' => $index,
            'task_type' => 'expense_reimbursement',
            'title' => "一括完了タスク{$index}",
            'status' => 'processing',
            'assigned_user_id' => $staff->id,
        ]));
        $otherTask = BackOfficeTask::query()->create([
            'source_type' => 'workflow_request',
            'source_id' => 3,
            'task_type' => 'expense_reimbursement',
            'title' => '他人のタスク',
            'status' => 'processing',
            'assigned_user_id' => $other->id,
        ]);

        $this->actingAs($staff)->postJson('/api/backoffice-tasks/bulk-complete', [
            'task_ids' => $tasks->pluck('id')->all(),
        ])->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.result', 'processed');

        foreach ($tasks as $task) {
            $this->assertDatabaseHas('backoffice_tasks', ['id' => $task->id, 'status' => 'completed']);
        }

        $rejected = $this->actingAs($staff)->postJson('/api/backoffice-tasks/bulk-complete', [
            'task_ids' => [$otherTask->id],
        ])->assertUnprocessable()->assertJsonPath('code', 'bulk_operation_rejected');
        $this->assertSame(
            ['自分に割り当てられていないタスクは一括完了できません。'],
            $rejected->json('errors')["items.{$otherTask->id}"],
        );
        $this->assertDatabaseHas('backoffice_tasks', ['id' => $otherTask->id, 'status' => 'processing']);
    }

    /**
     * 月次勤怠確認タスク(attendance_month_confirmation)は人事部(hr_staff)に割り当てるため、
     * hr_staffロールもバックオフィスタスクAPIにアクセスできる必要がある。
     */
    public function test_hr_staff_can_access_and_complete_attendance_month_task(): void
    {
        $hrStaff = User::factory()->create();
        $this->assignRole($hrStaff, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));

        $task = BackOfficeTask::query()->create([
            'source_type' => 'attendance_month',
            'source_id' => 1,
            'task_type' => 'attendance_month_confirmation',
            'title' => '月次勤怠確認: テスト社員 (2026-06)',
            'status' => 'not_started',
        ]);

        $this->actingAs($hrStaff)->getJson('/api/backoffice-tasks')->assertOk();

        $this->actingAs($hrStaff)->postJson("/api/backoffice-tasks/{$task->id}/assign", [
            'assigned_user_id' => $hrStaff->id,
        ])->assertOk()->assertJsonPath('status', 'in_review');

        $this->actingAs($hrStaff)->postJson("/api/backoffice-tasks/{$task->id}/status", [
            'status' => 'completed',
        ])->assertOk()->assertJsonPath('status', 'completed');

        $this->assertDatabaseHas('backoffice_tasks', ['id' => $task->id, 'status' => 'completed']);
    }

    public function test_assignee_scope_returns_only_matching_tasks(): void
    {
        [$staff, $other] = $this->createStaffAndOther();
        $tasks = $this->seedAssigneeFixture($staff, $other);

        $unassignedResponse = $this->actingAs($staff)->getJson('/api/backoffice-tasks?assignee=unassigned');
        $unassignedResponse->assertJsonPath('meta.total', 3);
        $this->assertListedTasks(
            $unassignedResponse,
            ['unassigned-open', 'unassigned-completed', 'unassigned-cancelled'],
            $tasks,
        );

        $this->assertListedTasks(
            $this->actingAs($staff)->getJson('/api/backoffice-tasks?assignee=me'),
            ['me-open', 'me-completed', 'me-cancelled'],
            $tasks,
        );

        $othersResponse = $this->actingAs($staff)->getJson('/api/backoffice-tasks?assignee=others');
        $this->assertListedTasks(
            $othersResponse,
            ['others-open', 'others-completed', 'others-cancelled'],
            $tasks,
        );
        $othersIds = $this->listedIds($othersResponse);
        $this->assertNotContains($tasks['unassigned-open']->id, $othersIds);
        $this->assertNotContains($tasks['me-open']->id, $othersIds);

        $allKeys = array_keys($tasks);
        $this->assertListedTasks(
            $this->actingAs($staff)->getJson('/api/backoffice-tasks?assignee=all'),
            $allKeys,
            $tasks,
        );
        $this->assertListedTasks(
            $this->actingAs($staff)->getJson('/api/backoffice-tasks'),
            $allKeys,
            $tasks,
        );
    }

    public function test_status_filter_excludes_completed_and_cancelled_for_open(): void
    {
        [$staff, $other] = $this->createStaffAndOther();
        $tasks = $this->seedAssigneeFixture($staff, $other);

        $this->assertListedTasks(
            $this->actingAs($staff)->getJson('/api/backoffice-tasks?status=open'),
            ['unassigned-open', 'me-open', 'others-open'],
            $tasks,
        );
        $this->assertListedTasks(
            $this->actingAs($staff)->getJson('/api/backoffice-tasks?status=completed'),
            ['unassigned-completed', 'me-completed', 'others-completed'],
            $tasks,
        );
        $this->assertListedTasks(
            $this->actingAs($staff)->getJson('/api/backoffice-tasks?status=all'),
            array_keys($tasks),
            $tasks,
        );
    }

    public function test_search_combines_with_assignee_and_status(): void
    {
        [$staff, $other] = $this->createStaffAndOther();
        $tasks = $this->seedAssigneeFixture($staff, $other);

        // 担当者名「他人担当」で検索すると、他人に割り当てたタスクだけが対象になる。
        $this->assertListedTasks(
            $this->actingAs($staff)->getJson('/api/backoffice-tasks?search='.urlencode('他人担当')),
            ['others-open', 'others-completed', 'others-cancelled'],
            $tasks,
        );
        $this->assertListedTasks(
            $this->actingAs($staff)->getJson('/api/backoffice-tasks?search='.urlencode('他人担当').'&assignee=others&status=open'),
            ['others-open'],
            $tasks,
        );
        $this->assertListedTasks(
            $this->actingAs($staff)->getJson('/api/backoffice-tasks?search='.urlencode('他人担当').'&assignee=me'),
            [],
            $tasks,
        );
    }

    public function test_assignee_counts_reflect_search_and_status_but_not_assignee(): void
    {
        [$staff, $other] = $this->createStaffAndOther();
        $this->seedAssigneeFixture($staff, $other);

        // status=open: 未完了は各担当者区分に1件ずつ。assigneeを変えても件数は同じ。
        $meResponse = $this->actingAs($staff)->getJson('/api/backoffice-tasks?status=open&assignee=me');
        $othersResponse = $this->actingAs($staff)->getJson('/api/backoffice-tasks?status=open&assignee=others');

        $meResponse->assertOk()->assertJsonPath('meta.total', 1);
        $othersResponse->assertOk()->assertJsonPath('meta.total', 1);
        $expectedCounts = ['unassigned' => 1, 'me' => 1, 'others' => 1, 'all' => 3];
        $this->assertSame($expectedCounts, $meResponse->json('meta.assignee_counts'));
        $this->assertSame($expectedCounts, $othersResponse->json('meta.assignee_counts'));

        // status=completed: 完了は各区分に1件ずつ。
        $this->assertSame(
            $expectedCounts,
            $this->actingAs($staff)->getJson('/api/backoffice-tasks?status=completed&assignee=unassigned')->json('meta.assignee_counts'),
        );

        // search=他人担当 (status=all): 他人担当の3件だけが件数に入る。assignee=unassignedでも一覧は0件。
        $searchResponse = $this->actingAs($staff)->getJson('/api/backoffice-tasks?search='.urlencode('他人担当').'&status=all&assignee=unassigned');
        $searchResponse->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(
            ['unassigned' => 0, 'me' => 0, 'others' => 3, 'all' => 3],
            $searchResponse->json('meta.assignee_counts'),
        );
    }

    public function test_invalid_assignee_or_status_is_unprocessable(): void
    {
        $staff = User::factory()->create();
        $this->assignRole($staff, Role::query()->create(['code' => Role::ACCOUNTING_STAFF, 'name' => '経理担当者']));

        $this->actingAs($staff)->getJson('/api/backoffice-tasks?assignee=unknown')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assignee']);

        $this->actingAs($staff)->getJson('/api/backoffice-tasks?status=unknown')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function createStaffAndOther(): array
    {
        $staff = User::factory()->create(['name' => '自分担当']);
        $other = User::factory()->create(['name' => '他人担当']);
        $this->assignRole($staff, Role::query()->create(['code' => Role::ACCOUNTING_STAFF, 'name' => '経理担当者']));

        return [$staff, $other];
    }

    /**
     * 担当者区分(未割当・自分・他人)×ステータス(未完了=in_review・完了・取消)の9件を作る。
     * キーは「{担当者区分}-{open|completed|cancelled}」。
     *
     * @return array<string, BackOfficeTask>
     */
    private function seedAssigneeFixture(User $staff, User $other): array
    {
        $owners = ['unassigned' => null, 'me' => $staff->id, 'others' => $other->id];
        $statuses = ['open' => 'in_review', 'completed' => 'completed', 'cancelled' => 'cancelled'];

        $tasks = [];
        foreach ($owners as $ownerKey => $assignedUserId) {
            foreach ($statuses as $statusKey => $status) {
                $tasks["{$ownerKey}-{$statusKey}"] = BackOfficeTask::query()->create([
                    'source_type' => 'workflow_request',
                    'source_id' => (string) (count($tasks) + 1),
                    'task_type' => 'expense_reimbursement',
                    'title' => "{$ownerKey}-{$statusKey}のタスク",
                    'status' => $status,
                    'assigned_user_id' => $assignedUserId,
                ]);
            }
        }

        return $tasks;
    }

    /**
     * @return array<int, string>
     */
    private function listedIds(TestResponse $response): array
    {
        $ids = collect($response->json('data'))->pluck('id')->all();
        sort($ids);

        return array_values($ids);
    }

    /**
     * レスポンスのタスクID集合が、期待するキー(seedAssigneeFixtureのキー)のタスク集合と一致することを確かめる。
     *
     * @param  array<int, string>  $expectedKeys
     * @param  array<string, BackOfficeTask>  $tasks
     */
    private function assertListedTasks(TestResponse $response, array $expectedKeys, array $tasks): void
    {
        $response->assertOk();

        $expectedIds = array_map(fn (string $key) => $tasks[$key]->id, $expectedKeys);
        sort($expectedIds);

        $this->assertSame(array_values($expectedIds), $this->listedIds($response));
    }
}
