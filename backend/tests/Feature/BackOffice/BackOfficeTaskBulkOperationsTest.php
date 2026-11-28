<?php

namespace Tests\Feature\BackOffice;

use App\Domain\BackOffice\Commands\AssignBackOfficeTasks;
use App\Domain\BackOffice\Commands\CompleteBackOfficeTasks;
use App\Models\BackOfficeTask;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AssertsSingleTopLevelCommand;
use Tests\TestCase;

/**
 * バックオフィスタスクの一括完了(`bulk-complete`)・担当の一括変更(`bulk-assign`)。
 * 全部か無しか: 1件でも失敗したら何も記録せず、422(code: bulk_operation_rejected)で失敗した対象と理由をすべて返す。
 */
class BackOfficeTaskBulkOperationsTest extends TestCase
{
    use AssertsSingleTopLevelCommand;
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->create();
        $this->assignRole($this->staff, Role::query()->create(['code' => Role::ACCOUNTING_STAFF, 'name' => '経理担当者']));
    }

    private static int $sourceId = 0;

    private function makeTask(?string $assignedUserId, string $status = 'processing', string $title = 'タスク'): BackOfficeTask
    {
        return BackOfficeTask::query()->create([
            'source_type' => 'workflow_request', 'source_id' => ++self::$sourceId,
            'task_type' => 'expense_reimbursement', 'title' => $title, 'status' => $status,
            'assigned_user_id' => $assignedUserId,
        ]);
    }

    private function eventCount(string $aggregateUuid): int
    {
        return DB::table('stored_events')->where('aggregate_uuid', $aggregateUuid)->count();
    }

    // ---- 一括完了 ----

    public function test_bulk_complete_completes_all_with_a_single_top_level_command(): void
    {
        $tasks = [$this->makeTask($this->staff->id), $this->makeTask($this->staff->id)];

        $bus = $this->recordCommands();
        $this->actingAs($this->staff)->postJson('/api/backoffice-tasks/bulk-complete', [
            'task_ids' => array_map(fn ($t) => $t->id, $tasks),
        ])->assertOk()->assertJsonCount(2, 'data');

        $this->assertSingleTopLevelCommand($bus, CompleteBackOfficeTasks::class);
        foreach ($tasks as $task) {
            $this->assertDatabaseHas('backoffice_tasks', ['id' => $task->id, 'status' => 'completed']);
            $this->assertSame(1, $this->eventCount($task->id));
        }
    }

    public function test_bulk_complete_skips_completed_tasks_without_recording_events(): void
    {
        $done = $this->makeTask($this->staff->id, 'completed');
        $open = $this->makeTask($this->staff->id);

        $response = $this->actingAs($this->staff)->postJson('/api/backoffice-tasks/bulk-complete', [
            'task_ids' => [$done->id, $open->id],
        ])->assertOk();

        $results = collect($response->json('data'))->pluck('result', 'id')->all();
        $this->assertSame('skipped', $results[$done->id]);
        $this->assertSame('processed', $results[$open->id]);
        $this->assertSame(0, $this->eventCount($done->id));
        $this->assertSame(1, $this->eventCount($open->id));
    }

    public function test_bulk_complete_records_nothing_when_some_fail_and_returns_every_failure(): void
    {
        $other = User::factory()->create();
        $mine = $this->makeTask($this->staff->id, title: '自分');
        $otherA = $this->makeTask($other->id, title: '他人A');
        $otherB = $this->makeTask(null, title: '未割当');

        $response = $this->actingAs($this->staff)->postJson('/api/backoffice-tasks/bulk-complete', [
            'task_ids' => [$mine->id, $otherA->id, $otherB->id],
        ])->assertUnprocessable()->assertJsonPath('code', 'bulk_operation_rejected');

        $errors = $response->json('errors');
        $this->assertCount(2, $errors);
        $this->assertArrayHasKey("items.{$otherA->id}", $errors);
        $this->assertArrayHasKey("items.{$otherB->id}", $errors);
        $this->assertDatabaseHas('backoffice_tasks', ['id' => $mine->id, 'status' => 'processing']);
        $this->assertSame(0, $this->eventCount($mine->id));
    }

    public function test_bulk_complete_rejects_more_than_100_and_duplicates(): void
    {
        $task = $this->makeTask($this->staff->id);

        $this->actingAs($this->staff)->postJson('/api/backoffice-tasks/bulk-complete', [
            'task_ids' => array_fill(0, 101, (string) Str::uuid()),
        ])->assertUnprocessable()->assertJsonValidationErrors(['task_ids']);

        $this->actingAs($this->staff)->postJson('/api/backoffice-tasks/bulk-complete', [
            'task_ids' => [$task->id, $task->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['task_ids.0']);
        $this->assertDatabaseHas('backoffice_tasks', ['id' => $task->id, 'status' => 'processing']);
    }

    // ---- 担当の一括変更 ----

    public function test_bulk_assign_assigns_all_with_a_single_top_level_command(): void
    {
        $assignee = User::factory()->create();
        $tasks = [$this->makeTask(null, 'not_started'), $this->makeTask($this->staff->id, 'processing')];

        $bus = $this->recordCommands();
        $this->actingAs($this->staff)->postJson('/api/backoffice-tasks/bulk-assign', [
            'task_ids' => array_map(fn ($t) => $t->id, $tasks), 'assigned_user_id' => $assignee->id,
        ])->assertOk()->assertJsonCount(2, 'data');

        $this->assertSingleTopLevelCommand($bus, AssignBackOfficeTasks::class);
        foreach ($tasks as $task) {
            $this->assertDatabaseHas('backoffice_tasks', ['id' => $task->id, 'assigned_user_id' => $assignee->id]);
        }
    }

    public function test_bulk_assign_rejects_unknown_task_ids_without_recording(): void
    {
        $assignee = User::factory()->create();
        $ok = $this->makeTask(null, 'not_started');

        $missingA = (string) Str::uuid();
        $missingB = (string) Str::uuid();

        // 存在しないIDは入力の検証(exists)で拒否される(何も記録されない)。
        $this->actingAs($this->staff)->postJson('/api/backoffice-tasks/bulk-assign', [
            'task_ids' => [$ok->id, $missingA, $missingB], 'assigned_user_id' => $assignee->id,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('backoffice_tasks', ['id' => $ok->id, 'assigned_user_id' => null]);
        $this->assertSame(0, $this->eventCount($ok->id));
    }

    public function test_bulk_assign_rolls_back_every_task_when_a_task_fails_in_the_handler(): void
    {
        $assignee = User::factory()->create();
        $ok = $this->makeTask(null, 'not_started', 'OK');
        $bad = $this->makeTask(null, 'not_started', 'NG');

        // 1件ごとの処理が失敗する状況をつくる: bad の行をバリデーション後・処理前に消すことはできないため、
        // Commandを直接dispatchして、存在しないIDが混じったときの全部戻しと失敗の一覧を確かめる。
        $missing = (string) Str::uuid();
        try {
            app(\App\Domain\EventSourcing\CommandBus::class)->dispatch(new AssignBackOfficeTasks(
                [$ok->id, $missing, $bad->id], $assignee->id, $this->staff->id,
            ));
            $this->fail('BulkOperationRejectedが投げられること');
        } catch (\App\Domain\EventSourcing\BulkOperationRejected $e) {
            $failures = $e->failures();
            $this->assertCount(1, $failures);
            $this->assertSame($missing, $failures[0]['id']);
        }

        $this->assertDatabaseHas('backoffice_tasks', ['id' => $ok->id, 'assigned_user_id' => null]);
        $this->assertDatabaseHas('backoffice_tasks', ['id' => $bad->id, 'assigned_user_id' => null]);
        $this->assertSame(0, $this->eventCount($ok->id));
        $this->assertSame(0, $this->eventCount($bad->id));
    }

    public function test_bulk_assign_rejects_more_than_100_and_duplicates(): void
    {
        $assignee = User::factory()->create();
        $task = $this->makeTask(null, 'not_started');

        $this->actingAs($this->staff)->postJson('/api/backoffice-tasks/bulk-assign', [
            'task_ids' => array_fill(0, 101, (string) Str::uuid()), 'assigned_user_id' => $assignee->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['task_ids']);

        $this->actingAs($this->staff)->postJson('/api/backoffice-tasks/bulk-assign', [
            'task_ids' => [$task->id, $task->id], 'assigned_user_id' => $assignee->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['task_ids.0']);
    }
}
