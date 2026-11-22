<?php

namespace Tests\Feature\EventSourcing;

use App\Console\CurrentAdminCommandRun;
use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use App\Models\AdminCommandRun;
use App\Models\User;
use App\Services\EventSourcing\ReadModelRebuildRunService;
use App\Services\PaidLeave\ScheduleAssessmentRunService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Feature\EventSourcing\Concerns\BuildsReadModelRebuildFixtures;
use Tests\TestCase;

/**
 * 旧形式の backoffice_task.assigned の移行(運用コマンド backoffice:migrate-assignment-events)のシナリオテスト
 * (docs/changesets/20261122-backoffice-assignment-migration-admin/spec.md)。
 *
 * 旧イベントは今のコードでは記録できないため、生の行を直接入れる(PaidLeaveStreamRekeyScenarioTest と同じ)。
 * 期待値は旧 BackOfficeTaskProjector の最終状態として固定する(assigned は assigned_user_id を設定し status を常に in_review にしていた)。
 * 実際に ReadModel を空にして再生するので`DatabaseMigrations`(ReadModelRebuildCommandTest と同じ)。
 */
class BackOfficeAssignmentEventRebuildScenarioTest extends TestCase
{
    use BuildsReadModelRebuildFixtures;
    use DatabaseMigrations;

    private const COMMAND = 'backoffice:migrate-assignment-events';

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

    /** @var array<string, int> 旧形式の履歴の主な行の旧id */
    private array $oldIds = [];

    public function runDatabaseMigrations()
    {
        $this->runRebuildDatabaseMigrations();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearRebuildHeartbeat();
        config(['read_model_rebuild.claim_wait_seconds' => 0]);
        $this->seedAccessControl();
    }

    protected function tearDown(): void
    {
        $this->clearRebuildHeartbeat();
        parent::tearDown();
    }

    public function test_dry_run_changes_nothing_and_shows_the_counts_and_changed_tables(): void
    {
        $this->buildLegacyHistory();
        $before = $this->snapshot();

        $this->artisan(self::COMMAND, ['--dry-run' => true])
            ->expectsOutputToContain('変換した旧形式の assigned: 3 件 / 挿入した status_changed: 3 件')
            ->expectsOutputToContain('backoffice_tasks')
            ->expectsOutputToContain('変更あり')
            ->assertExitCode(0);

        $this->assertEquals($before, $this->snapshot());
        $this->assertSame([], $this->backupTables(), '試し実行はバックアップ表を作らない');
        $run = AdminCommandRun::query()->where('command_name', self::COMMAND)->sole();
        $this->assertSame('succeeded', $run->status);
        $this->assertTrue($run->summary['dry_run']);
        $this->assertSame(3, $run->summary['target_count']);
        $this->assertGreaterThan(0, $run->summary['changed_tables']);
        $this->assertFalse(app(ReadModelRebuildRunService::class)->isRunning());
    }

    public function test_apply_migrates_stored_events_and_rebuilds_the_read_models_in_one_transaction(): void
    {
        $this->buildLegacyHistory();
        $before = $this->storedEvents();
        $beforeCount = count($before);

        $this->artisan(self::COMMAND)->assertExitCode(0);

        // 旧形式の assigned は0件。status_changed が3件挿入され、全体で3件増える。
        $this->assertSame($beforeCount + 3, DB::table('stored_events')->count());
        $assigned = DB::table('stored_events')->where('event_class', 'backoffice_task.assigned')->get();
        $this->assertCount(3, $assigned);
        foreach ($assigned as $row) {
            $this->assertArrayHasKey('previousAssignedUserId', json_decode($row->event_properties, true));
        }

        // 集約ごとの並びと版・meta。id は欠番なしの連番。
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

        // ReadModel は(同じトランザクションで)作り直し済み: 旧 Projector の最終状態になる。
        $a = DB::table('backoffice_tasks')->where('id', $this->taskA)->first();
        $this->assertSame($this->second->id, $a->assigned_user_id);
        $this->assertSame('in_review', $a->status);
        $this->assertNull($a->completed_at);
        $b = DB::table('backoffice_tasks')->where('id', $this->taskB)->first();
        $this->assertSame('completed', $b->status);
        $this->assertSame($this->first->id, $b->assigned_user_id);
        $this->assertSame(self::COMPLETED_AT, (string) $b->completed_at);

        // 他の文脈の履歴の stored_event_id は新しい id を指し、衝突しない。
        $entries = DB::table('workflow_request_history_entries')->where('workflow_request_id', $this->workflow)->orderBy('stored_event_id')->get();
        $this->assertCount(2, $entries);
        $ids = DB::table('stored_events')->where('aggregate_uuid', $this->workflow)->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->assertSame($ids, $entries->pluck('stored_event_id')->map(fn ($v) => (int) $v)->all());

        // バックアップ表は元の行と一致する。
        $backups = $this->backupTables();
        $this->assertCount(1, $backups);
        $this->assertEquals($before, $this->storedEvents($backups[0]));

        $run = AdminCommandRun::query()->where('command_name', self::COMMAND)->sole();
        $this->assertSame('succeeded', $run->status);
        $this->assertFalse($run->summary['dry_run']);
        $this->assertSame($backups[0], $run->summary['backup_table']);
        $this->assertSame(3, $run->summary['inserted_count']);
        $this->assertSame(['not_started' => 2, 'processing' => 1], $run->summary['previous_status_counts']);

        // 2回目: 対象なし。何も変えず、バックアップ表も増えない。
        $after = $this->snapshot();
        $this->artisan(self::COMMAND)->expectsOutputToContain('対象なし')->assertExitCode(0);
        $this->assertEquals($after, $this->snapshot());
        $this->assertCount(1, $this->backupTables());
    }

    public function test_correction_log_and_meta_source_ids_follow_the_new_ids_without_unique_collisions(): void
    {
        $this->buildLegacyHistory();
        $unmappedBefore = DB::table('stored_event_corrections')->where('correction_key', 'unmapped')->first();

        $this->artisan(self::COMMAND)->assertExitCode(0);

        $logs = DB::table('stored_event_corrections')->where('correction_key', 'mapped')->get();
        $this->assertCount(4, $logs);
        foreach ($logs as $log) {
            $event = DB::table('stored_events')->where('id', $log->stored_event_id)->first();
            $this->assertNotNull($event, "補正ログ {$log->id} の stored_event_id が存在する行を指す");
            $this->assertSame($log->aggregate_uuid, $event->aggregate_uuid);
            $this->assertSame($log->event_class, $event->event_class);
            $this->assertSame((int) $event->aggregate_version, (int) $log->aggregate_version, 'aggregate_version も新しい版');
            $marker = json_decode($log->before_event_properties, true)['marker'] ?? null;
            if ($marker !== null) {
                $this->assertSame($marker, json_decode($event->event_properties, true)['assignedUserId']);
            }
        }
        // 旧 status_changed(版3)は挿入により版4になる。
        $statusChanged = $logs->firstWhere('event_class', 'backoffice_task.status_changed');
        $this->assertSame(4, (int) $statusChanged->aggregate_version);

        // 対応の無い行は不変。
        $this->assertEquals($unmappedBefore, DB::table('stored_event_corrections')->where('correction_key', 'unmapped')->first());

        // meta の source_event_id は、新しい id を指す(承認イベントの行)。
        $created = DB::table('stored_events')->where('aggregate_uuid', $this->taskA)->where('aggregate_version', 1)->first();
        $sourceId = json_decode($created->meta_data, true)['history-normalization']['source_event_id'];
        $approved = DB::table('stored_events')->where('id', $sourceId)->first();
        $this->assertSame('workflow_request.approved', $approved->event_class);

        $run = AdminCommandRun::query()->where('command_name', self::COMMAND)->sole();
        $this->assertSame(4, $run->summary['corrections_relinked']);
        $this->assertSame(1, $run->summary['corrections_unmapped']);
    }

    public function test_a_queued_run_through_the_runner_saves_the_output_and_summary(): void
    {
        $this->buildLegacyHistory();
        $admin = $this->createEventAdmin();
        $run = AdminCommandRun::query()->create([
            'command_name' => self::COMMAND, 'parameters' => ['dry-run' => true], 'status' => 'queued', 'requested_by_user_id' => $admin->id,
        ]);

        $this->assertSame(0, Artisan::call('admin-commands:run-pending'));

        $fresh = $run->fresh();
        $this->assertSame('succeeded', $fresh->status);
        $this->assertTrue($fresh->summary['dry_run']);
        $this->assertStringContainsString('変換した旧形式の assigned: 3 件', $fresh->output);
        $this->assertStringContainsString('| テーブル', $fresh->output);
        $this->assertSame([], $this->backupTables());
        $this->assertSame(0, DB::table('backoffice_tasks')->count());
    }

    public function test_a_failure_rolls_back_stored_events_read_models_and_corrections_and_reports_the_backup(): void
    {
        $this->buildLegacyHistory();
        $before = $this->snapshot();
        $this->partialMock(ReadModelRebuilder::class, function ($mock): void {
            $mock->shouldReceive('rebuild')->once()->andThrow(new RuntimeException('boom'));
        });

        $this->artisan(self::COMMAND)->expectsOutputToContain('boom')->assertExitCode(1);

        $this->assertEquals($before, $this->snapshot());
        $backups = $this->backupTables();
        $this->assertCount(1, $backups);
        $run = AdminCommandRun::query()->where('command_name', self::COMMAND)->sole();
        $this->assertSame('failed', $run->status);
        $this->assertSame(1, $run->exit_code);
        $this->assertStringContainsString('boom', $run->error_message);
        $this->assertStringContainsString('ロールバックしたため', $run->error_message);
        $this->assertStringContainsString($backups[0], $run->error_message);
        $this->assertFalse(app(ReadModelRebuildRunService::class)->isRunning());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_a_violation_fails_without_changing_anything(): void
    {
        $this->first = User::factory()->create();
        $this->insertRaw($this->taskA, 'backoffice_task.assigned', ['assignedUserId' => 'x', 'assignedByUserId' => 'y']);
        $before = $this->snapshot();

        $this->artisan(self::COMMAND, ['--dry-run' => true])->expectsOutputToContain('変換できない並び')->assertExitCode(1);
        $this->artisan(self::COMMAND)->expectsOutputToContain('変換できない並び')->assertExitCode(1);

        $this->assertEquals($before, $this->snapshot());
        $this->assertSame(
            1,
            DB::table('stored_events')->where('event_class', 'backoffice_task.assigned')->whereNull('event_properties->previousAssignedUserId')->count(),
        );
        $this->assertSame(2, AdminCommandRun::query()->where('command_name', self::COMMAND)->where('status', 'failed')->count());
    }

    public function test_nothing_to_migrate_succeeds_without_changes_or_a_read_model_rebuild(): void
    {
        $this->insertRaw($this->taskA, 'backoffice_task.created', $this->created());
        $this->insertRaw($this->taskA, 'backoffice_task.assigned', ['assignedUserId' => 'x', 'assignedByUserId' => 'y', 'previousAssignedUserId' => null]);
        // イベントを経ずに作った利用者: ReadModel を作り直すと消える。
        $stray = User::factory()->create();
        $before = $this->snapshot();

        $this->artisan(self::COMMAND)->expectsOutputToContain('対象なし')->assertExitCode(0);

        $this->assertEquals($before, $this->snapshot());
        $this->assertTrue(DB::table('users')->where('id', $stray->id)->exists());
        $this->assertSame([], $this->backupTables());
        $this->assertSame('nothing', AdminCommandRun::query()->where('command_name', self::COMMAND)->sole()->summary['status']);
    }

    public function test_no_ddl_is_issued_inside_the_transaction(): void
    {
        $this->buildLegacyHistory();
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = [$query->sql, DB::transactionLevel()];
        });

        $this->artisan(self::COMMAND)->assertExitCode(0);

        $ddl = fn (array $s) => preg_match('/^\s*(CREATE|ALTER|DROP|RENAME|TRUNCATE)\b/i', $s[0]) === 1;
        $this->assertSame([], array_values(array_filter($statements, fn (array $s) => $ddl($s) && $s[1] > 0)));
        $this->assertNotEmpty(array_filter($statements, fn (array $s) => $ddl($s) && $s[1] === 0), 'バックアップ表の作成はトランザクションの外');
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_the_run_is_in_maintenance_while_the_migration_is_running(): void
    {
        $this->buildLegacyHistory();
        $during = [];
        $this->partialMock(ReadModelRebuilder::class, function ($mock) use (&$during): void {
            $mock->shouldReceive('rebuild')->once()->andReturnUsing(function () use (&$during): array {
                $during[] = app(ReadModelRebuildRunService::class)->isRunning();

                return ['dry_run' => false, 'tables' => [], 'changed_tables' => 0];
            });
        });

        $this->artisan(self::COMMAND)->assertExitCode(0);

        $this->assertSame([true], $during);
        $this->assertFalse(app(ReadModelRebuildRunService::class)->isRunning());
    }

    public function test_a_migration_run_makes_the_maintenance_status_true_and_is_exclusive_with_read_models_rebuild(): void
    {
        $service = app(ReadModelRebuildRunService::class);
        $running = $service->startDirectRun(false, self::COMMAND);
        $this->assertSame(self::COMMAND, $running->command_name);
        $this->assertTrue($service->isRunning());
        $this->getJson('/api/maintenance-status')->assertExactJson(['maintenance' => true]);

        // 移行の実行中は read-models:rebuild を開始しない(直接実行も、実行役経由の記録も)。
        $this->artisan('read-models:rebuild')->expectsOutputToContain('作り直しを実行中です')->assertExitCode(1);
        $this->assertNull($service->startDirectRun(false));
        $other = AdminCommandRun::query()->create(['command_name' => 'read-models:rebuild', 'parameters' => ['dry-run' => true], 'status' => 'running', 'started_at' => now()]);
        app()->instance(CurrentAdminCommandRun::class, new CurrentAdminCommandRun($other));
        $this->artisan('read-models:rebuild', ['--dry-run' => true])->assertExitCode(1);
        app()->forgetInstance(CurrentAdminCommandRun::class);
        $this->assertStringContainsString('作り直しを実行中', $other->fresh()->error_message);
    }

    public function test_the_migration_is_not_started_while_a_read_models_rebuild_is_running_and_vice_versa(): void
    {
        $this->buildLegacyHistory();
        $service = app(ReadModelRebuildRunService::class);
        $rebuild = $service->startDirectRun(false);
        $before = $this->snapshot();

        $this->artisan(self::COMMAND)->expectsOutputToContain('作り直しを実行中です')->assertExitCode(1);
        $this->assertEquals($before, $this->snapshot());
        $this->assertSame(0, AdminCommandRun::query()->where('command_name', self::COMMAND)->count());

        // 実行役経由(自分の記録は running)でも、他の記録が running なら失敗にする。
        $own = AdminCommandRun::query()->create(['command_name' => self::COMMAND, 'parameters' => ['dry-run' => false], 'status' => 'running', 'started_at' => now()]);
        app()->instance(CurrentAdminCommandRun::class, new CurrentAdminCommandRun($own));
        $this->artisan(self::COMMAND)->assertExitCode(1);
        app()->forgetInstance(CurrentAdminCommandRun::class);
        $this->assertNotNull($own->fresh()->error_message);
        $this->assertEquals($before, $this->snapshot());

        // 逆: 移行の記録が running のとき read-models:rebuild の記録を開始しない。
        $rebuild->update(['status' => 'succeeded']);
        $own->update(['status' => 'failed']);
        $migrate = $service->startDirectRun(false, self::COMMAND);
        $this->assertNotNull($migrate);
        $this->assertNull($service->startDirectRun(false));
    }

    public function test_the_migration_is_not_started_while_an_assessment_is_active(): void
    {
        $admin = $this->createEventAdmin();
        AdminCommandRun::query()->create([
            'command_name' => ScheduleAssessmentRunService::COMMAND_NAME,
            'parameters' => ['trigger' => 'manual'], 'status' => 'queued', 'requested_by_user_id' => $admin->id,
        ]);
        $this->buildLegacyHistory();
        $before = $this->snapshot();

        $this->assertTrue(app(ScheduleAssessmentRunService::class)->hasActiveRun());
        $this->assertNull(app(ReadModelRebuildRunService::class)->startDirectRun(false, self::COMMAND));
        $this->artisan(self::COMMAND)->expectsOutputToContain('有給付与予定の判定の実行中です')->assertExitCode(1);
        $this->assertEquals($before, $this->snapshot());
    }

    public function test_the_backup_must_match_stored_events(): void
    {
        $this->buildLegacyHistory();
        $before = $this->snapshot();
        // バックアップ後に stored_events が増えたことを再現する(バックアップ作成後・読み込み前の書き込み)。
        $done = false;
        DB::listen(function ($query) use (&$done): void {
            if (! $done && str_starts_with(trim($query->sql), 'INSERT INTO stored_events_before_rebuild_')) {
                $done = true;
                $this->insertRaw('44444444-4444-4444-8444-444444444444', 'backoffice_task.created', $this->created());
            }
        });

        $this->artisan(self::COMMAND)->expectsOutputToContain('一致しません')->assertExitCode(1);

        $after = $this->snapshot();
        $this->assertCount(count($before['stored_events']) + 1, $after['stored_events']);
        $this->assertSame(0, DB::table('backoffice_tasks')->count());
    }

    /**
     * A: created → assigned(旧,first) → status_changed(in_review→processing) → assigned(旧,second)  最終: second・in_review
     * B: created → assigned(旧,first) → completed  最終: completed(completed_at=COMPLETED_AT)
     * 他文脈: workflow_request の submitted・approved を間に挟む(stored_event_id を持つ履歴Projectorが反応する)。
     * 欠番のあるid(補正で削除された想定)と、旧idを指す補正ログ・metaのsource_event_idも用意する。
     */
    private function buildLegacyHistory(): void
    {
        $this->first = $this->createEventUser('First');
        $this->second = $this->createEventUser('Second');
        $this->manager = $this->createEventUser('Manager');

        $this->insertRaw($this->taskA, 'backoffice_task.created', $this->created());
        $this->insertRaw($this->workflow, 'workflow_request.submitted', ['approverUserId' => $this->manager->id, 'submittedByUserId' => $this->first->id]);
        $this->insertRaw($this->taskB, 'backoffice_task.created', $this->created());
        $this->nextId += 3; // 欠番
        $this->oldIds['a1'] = $this->insertRaw($this->taskA, 'backoffice_task.assigned', ['assignedUserId' => $this->first->id, 'assignedByUserId' => $this->manager->id]);
        $this->oldIds['b1'] = $this->insertRaw($this->taskB, 'backoffice_task.assigned', ['assignedUserId' => $this->first->id, 'assignedByUserId' => $this->manager->id]);
        $this->oldIds['a_sc'] = $this->insertRaw($this->taskA, 'backoffice_task.status_changed', ['previousStatus' => 'in_review', 'newStatus' => 'processing', 'changedByUserId' => $this->first->id, 'comment' => null]);
        $this->insertRaw($this->taskB, 'backoffice_task.completed', ['completedByUserId' => $this->first->id, 'comment' => null], self::COMPLETED_AT);
        $this->oldIds['approved'] = $this->insertRaw($this->workflow, 'workflow_request.approved', ['approvedByUserId' => $this->manager->id]);
        $this->oldIds['a2'] = $this->insertRaw($this->taskA, 'backoffice_task.assigned', ['assignedUserId' => $this->second->id, 'assignedByUserId' => $this->manager->id]);

        // taskA の created の meta が、承認イベントの旧idを指す(AttendanceBackOfficeTaskHistoryBackfiller と同じ構造)。
        $created = DB::table('stored_events')->where('aggregate_uuid', $this->taskA)->where('aggregate_version', 1)->first();
        $meta = json_decode($created->meta_data, true);
        $meta['history-normalization'] = ['source' => 'attendance_month.approved', 'source_event_id' => $this->oldIds['approved']];
        DB::table('stored_events')->where('id', $created->id)->update(['meta_data' => json_encode($meta)]);

        // 補正ログ: 連続した旧idの行が、新idでは1つずつずれる(逃がさずに更新すると unique に衝突する並び)。aggregate_version は旧い版。
        $this->correction('mapped', $this->oldIds['a1'], $this->taskA, 2, 'backoffice_task.assigned', ['marker' => $this->first->id]);
        $this->correction('mapped', $this->oldIds['b1'], $this->taskB, 2, 'backoffice_task.assigned', ['marker' => $this->first->id]);
        $this->correction('mapped', $this->oldIds['a_sc'], $this->taskA, 3, 'backoffice_task.status_changed', []);
        $this->correction('mapped', $this->oldIds['approved'], $this->workflow, 2, 'workflow_request.approved', []);
        // 対応の無い行(補正で削除されたイベントを指す)。
        $this->correction('unmapped', 987654, $this->workflow, 7, 'workflow_request.approved', []);
    }

    /** @param  array<string, mixed>  $before */
    private function correction(string $key, int $eventId, string $aggregate, int $version, string $class, array $before): void
    {
        DB::table('stored_event_corrections')->insert([
            'correction_key' => $key, 'stored_event_id' => $eventId, 'operation' => 'rewrite', 'aggregate_uuid' => $aggregate,
            'aggregate_version' => $version, 'event_class' => $class, 'before_event_properties' => json_encode($before ?: new \stdClass),
            'after_event_properties' => null, 'backup_table' => 'stored_events_backup_x', 'applied_at' => self::CREATED_AT,
        ]);
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

    /** @return list<string> */
    private function backupTables(): array
    {
        return array_values(array_filter(
            array_map(fn ($t) => is_array($t) ? $t['name'] : $t, Schema::getTables()),
            fn (string $name) => str_starts_with($name, 'stored_events_before_rebuild_'),
        ));
    }

    /** @return array<string, mixed> stored_events・補正ログ・主な ReadModel の中身 */
    private function snapshot(): array
    {
        return [
            'stored_events' => $this->storedEvents(),
            'corrections' => DB::table('stored_event_corrections')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'backoffice_tasks' => DB::table('backoffice_tasks')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'workflow_history' => DB::table('workflow_request_history_entries')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'users' => DB::table('users')->orderBy('id')->pluck('id')->all(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function storedEvents(string $table = 'stored_events'): array
    {
        $rows = [];
        foreach (DB::table($table)->orderBy('id')->get() as $row) {
            $rows[(int) $row->id] = [
                'aggregate_uuid' => $row->aggregate_uuid, 'aggregate_version' => (int) $row->aggregate_version, 'event_class' => $row->event_class,
                'event_properties' => json_decode($row->event_properties, true), 'meta_data' => json_decode($row->meta_data, true), 'created_at' => (string) $row->created_at,
            ];
        }

        return $rows;
    }

    /** @param  array<string, mixed>  $payload */
    private function insertRaw(string $aggregate, string $class, array $payload, string $at = self::CREATED_AT): int
    {
        if ($this->nextId <= 1) {
            $this->nextId = (int) DB::table('stored_events')->max('id') + 1;
        }
        $this->versions[$aggregate] = ($this->versions[$aggregate] ?? 0) + 1;
        $id = $this->nextId++;
        DB::table('stored_events')->insert([
            'id' => $id,
            'aggregate_uuid' => $aggregate,
            'aggregate_version' => $this->versions[$aggregate],
            'event_version' => 1,
            'event_class' => $class,
            'event_properties' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'meta_data' => json_encode([
                'aggregate-root-uuid' => $aggregate,
                'aggregate-root-version' => $this->versions[$aggregate],
                'stored-event-id' => $id,
                'created-at' => $at,
            ]),
            'created_at' => $at,
        ]);

        return $id;
    }
}
