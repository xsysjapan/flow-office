<?php

namespace Tests\Feature\EventSourcing;

use App\Domain\EventSourcing\Rebuild\EventStoreRebuildWriter;
use App\Domain\EventSourcing\Rebuild\ReadModelTableLister;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * バックオフィスタスクの旧形式 assigned の作り直し(backoffice:rebuild-assignment-events)のシナリオテスト
 * (docs/changesets/20261122-backoffice-task-reassign 論点6)。
 *
 * 旧イベントは今のコードでは記録できないため、生の行を直接入れる(PaidLeaveStreamRekeyScenarioTest と同じ)。
 * 期待値は旧 BackOfficeTaskProjector の最終状態として固定する(assigned は assigned_user_id を設定し status を常に in_review にしていた)。
 */
class BackOfficeAssignmentEventRebuildScenarioTest extends TestCase
{
    use RefreshDatabase;

    private const COMMAND = 'backoffice:rebuild-assignment-events';

    private const CREATED_AT = '2026-09-01 10:00:00';

    private const COMPLETED_AT = '2026-09-05 15:30:00';

    private int $nextId = 1;

    /** @var array<string, int> */
    private array $versions = [];

    private string $taskA = '11111111-1111-4111-8111-111111111111';

    private string $taskB = '22222222-2222-4222-8222-222222222222';

    private string $workflow = '33333333-3333-4333-8333-333333333333';

    private User $first;

    private User $second;

    private User $manager;

    public function test_dry_run_apply_verify_swap_and_replay_give_the_old_final_state(): void
    {
        $this->buildLegacyHistory();
        $before = $this->storedEvents();
        $beforeCount = count($before);

        // 試し実行: 何も書かない。
        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('変換する旧形式の assigned: 3 件 / 挿入する status_changed: 3 件')
            ->expectsOutputToContain('事前条件: 満たしている')
            ->assertExitCode(0);
        $this->assertFalse(Schema::hasTable(EventStoreRebuildWriter::TABLE));
        $this->assertEquals($before, $this->storedEvents());

        $this->artisan(self::COMMAND, ['--apply' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable(EventStoreRebuildWriter::TABLE));
        $this->assertSame($beforeCount, DB::table('stored_events')->count(), '--apply では stored_events を変えない');
        $this->artisan(self::COMMAND, ['--verify' => true])->assertExitCode(0);
        $this->artisan(self::COMMAND, ['--swap' => true])->assertExitCode(0);

        // 旧形式の assigned は0件。status_changed が3件挿入され、全体で3件増える。
        $this->assertSame($beforeCount + 3, DB::table('stored_events')->count());
        $assigned = DB::table('stored_events')->where('event_class', 'backoffice_task.assigned')->get();
        $this->assertCount(3, $assigned);
        foreach ($assigned as $row) {
            $this->assertArrayHasKey('previousAssignedUserId', json_decode($row->event_properties, true));
        }

        // 集約ごとの並びと版・meta。
        $this->assertSame(
            ['created', 'assigned', 'status_changed', 'status_changed', 'assigned', 'status_changed'],
            $this->classesOf($this->taskA),
        );
        $this->assertSame(['created', 'assigned', 'status_changed', 'completed'], $this->classesOf($this->taskB));
        foreach ([$this->taskA, $this->taskB] as $task) {
            $rows = DB::table('stored_events')->where('aggregate_uuid', $task)->orderBy('id')->get();
            $this->assertSame(range(1, $rows->count()), $rows->pluck('aggregate_version')->map(fn ($v) => (int) $v)->all());
            foreach ($rows as $row) {
                $meta = json_decode($row->meta_data, true);
                $this->assertSame((int) $row->aggregate_version, $meta['aggregate-root-version']);
                $this->assertSame((int) $row->id, $meta['stored-event-id']);
                $this->assertSame($task, $meta['aggregate-root-uuid']);
            }
        }
        $this->assertSame(range(1, DB::table('stored_events')->count()), DB::table('stored_events')->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all());

        // 2回目: 対象なし。
        $this->artisan(self::COMMAND)->expectsOutputToContain('対象なし')->assertExitCode(0);

        // 全ReadModelを空にして全Projectorをreplayすると、旧Projectorの最終状態になる。
        $this->replayAllReadModels();
        $a = DB::table('backoffice_tasks')->where('id', $this->taskA)->first();
        $this->assertSame($this->second->id, $a->assigned_user_id);
        $this->assertSame('in_review', $a->status);
        $this->assertNull($a->completed_at);
        $b = DB::table('backoffice_tasks')->where('id', $this->taskB)->first();
        $this->assertSame('completed', $b->status);
        $this->assertSame($this->first->id, $b->assigned_user_id);
        $this->assertSame(self::COMPLETED_AT, (string) $b->completed_at);
    }

    public function test_other_context_history_survives_the_rebuild_without_stored_event_id_collisions(): void
    {
        $this->buildLegacyHistory();
        $this->artisan(self::COMMAND, ['--apply' => true])->assertExitCode(0);
        $this->artisan(self::COMMAND, ['--swap' => true])->assertExitCode(0);

        $this->replayAllReadModels();

        $entries = DB::table('workflow_request_history_entries')->where('workflow_request_id', $this->workflow)->orderBy('stored_event_id')->get();
        $this->assertCount(2, $entries);
        $ids = DB::table('stored_events')->where('aggregate_uuid', $this->workflow)->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->assertSame($ids, $entries->pluck('stored_event_id')->map(fn ($v) => (int) $v)->all());
        $this->assertCount(2, array_unique($ids));
    }

    public function test_nothing_is_written_when_there_is_no_legacy_assigned(): void
    {
        $this->insertRaw($this->taskA, 'backoffice_task.created', $this->created());

        $this->artisan(self::COMMAND, ['--apply' => true])->expectsOutputToContain('対象なし')->assertExitCode(0);
        $this->assertFalse(Schema::hasTable(EventStoreRebuildWriter::TABLE));
    }

    public function test_violation_stops_the_rebuild(): void
    {
        $this->insertRaw($this->taskA, 'backoffice_task.assigned', ['assignedUserId' => 'x', 'assignedByUserId' => 'y']);

        $this->artisan(self::COMMAND)->expectsOutputToContain('未対応')->assertExitCode(1);
        $this->artisan(self::COMMAND, ['--apply' => true])->assertExitCode(1);
        $this->assertFalse(Schema::hasTable(EventStoreRebuildWriter::TABLE));
    }

    public function test_preconditions_stop_apply_when_snapshots_exist(): void
    {
        $this->buildLegacyHistory();
        $this->assertTrue(Schema::hasTable('snapshots'));
        DB::table('snapshots')->insert([
            'aggregate_uuid' => $this->taskA, 'aggregate_version' => 1, 'state' => '{}', 'created_at' => self::CREATED_AT, 'updated_at' => self::CREATED_AT,
        ]);

        $this->artisan(self::COMMAND)->expectsOutputToContain('snapshots')->assertExitCode(1);
        $this->artisan(self::COMMAND, ['--apply' => true])->assertExitCode(1);
        $this->assertFalse(Schema::hasTable(EventStoreRebuildWriter::TABLE));
    }

    public function test_apply_is_refused_when_the_rebuilt_table_already_exists(): void
    {
        $this->buildLegacyHistory();
        $this->artisan(self::COMMAND, ['--apply' => true])->assertExitCode(0);

        $this->artisan(self::COMMAND, ['--apply' => true])->assertExitCode(1);
    }

    public function test_swap_is_refused_when_the_verify_fails(): void
    {
        $this->buildLegacyHistory();
        $this->artisan(self::COMMAND, ['--apply' => true])->assertExitCode(0);
        DB::table(EventStoreRebuildWriter::TABLE)->where('event_class', 'workflow_request.submitted')->update(['event_properties' => '{"approverUserId":"tampered","submittedByUserId":"x"}']);

        $this->artisan(self::COMMAND, ['--verify' => true])->assertExitCode(1);
        $this->artisan(self::COMMAND, ['--swap' => true])->assertExitCode(1);
        $this->assertSame(1, DB::table('stored_events')->where('event_class', 'backoffice_task.assigned')->whereNull('event_properties->previousAssignedUserId')->where('aggregate_uuid', $this->taskB)->count());
    }

    /**
     * A: created → assigned(旧,first) → status_changed(in_review→processing) → assigned(旧,second)  最終: second・in_review
     * B: created → assigned(旧,first) → completed  最終: completed(completed_at=COMPLETED_AT)
     * 他文脈: workflow_request の drafted は省き submitted・approved を間に挟む(stored_event_id を持つ履歴Projectorが反応する)。
     */
    private function buildLegacyHistory(): void
    {
        $this->first = User::factory()->create();
        $this->second = User::factory()->create();
        $this->manager = User::factory()->create();

        $this->insertRaw($this->taskA, 'backoffice_task.created', $this->created());
        $this->insertRaw($this->workflow, 'workflow_request.submitted', ['approverUserId' => $this->manager->id, 'submittedByUserId' => $this->first->id]);
        $this->insertRaw($this->taskB, 'backoffice_task.created', $this->created());
        $this->insertRaw($this->taskA, 'backoffice_task.assigned', ['assignedUserId' => $this->first->id, 'assignedByUserId' => $this->manager->id]);
        $this->insertRaw($this->taskB, 'backoffice_task.assigned', ['assignedUserId' => $this->first->id, 'assignedByUserId' => $this->manager->id]);
        $this->insertRaw($this->taskA, 'backoffice_task.status_changed', ['previousStatus' => 'in_review', 'newStatus' => 'processing', 'changedByUserId' => $this->first->id, 'comment' => null]);
        $this->insertRaw($this->taskB, 'backoffice_task.completed', ['completedByUserId' => $this->first->id, 'comment' => null], self::COMPLETED_AT);
        $this->insertRaw($this->workflow, 'workflow_request.approved', ['approvedByUserId' => $this->manager->id]);
        $this->insertRaw($this->taskA, 'backoffice_task.assigned', ['assignedUserId' => $this->second->id, 'assignedByUserId' => $this->manager->id]);
    }

    /** @return array<string, mixed> */
    private function created(): array
    {
        return ['sourceType' => 'workflow_request', 'sourceId' => $this->workflow, 'taskType' => 'general', 'title' => 'task', 'assignedDepartment' => null, 'dueOn' => null];
    }

    /** @return list<string> */
    private function classesOf(string $task): array
    {
        return DB::table('stored_events')->where('aggregate_uuid', $task)->orderBy('id')->pluck('event_class')
            ->map(fn (string $c) => substr($c, strlen('backoffice_task.')))->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function storedEvents(): array
    {
        $rows = [];
        foreach (DB::table('stored_events')->orderBy('id')->get() as $row) {
            $rows[(int) $row->id] = [
                'aggregate_uuid' => $row->aggregate_uuid, 'aggregate_version' => (int) $row->aggregate_version, 'event_class' => $row->event_class,
                'event_properties' => json_decode($row->event_properties, true), 'meta_data' => json_decode($row->meta_data, true), 'created_at' => (string) $row->created_at,
            ];
        }

        return $rows;
    }

    private function replayAllReadModels(): void
    {
        DB::statement('PRAGMA defer_foreign_keys = ON');
        foreach (array_keys((new ReadModelTableLister)->tables(app_path('Domain'))) as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
        Artisan::call('event-sourcing:replay', ['--force' => true]);
    }

    /** @param  array<string, mixed>  $payload */
    private function insertRaw(string $aggregate, string $class, array $payload, string $at = self::CREATED_AT): void
    {
        $this->versions[$aggregate] = ($this->versions[$aggregate] ?? 0) + 1;
        DB::table('stored_events')->insert([
            'id' => $this->nextId,
            'aggregate_uuid' => $aggregate,
            'aggregate_version' => $this->versions[$aggregate],
            'event_version' => 1,
            'event_class' => $class,
            'event_properties' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'meta_data' => json_encode([
                'aggregate-root-uuid' => $aggregate,
                'aggregate-root-version' => $this->versions[$aggregate],
                'stored-event-id' => $this->nextId,
                'created-at' => $at,
            ]),
            'created_at' => $at,
        ]);
        $this->nextId++;
    }
}
