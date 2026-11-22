<?php

namespace Tests\Feature\EventSourcing;

use App\Console\AdminCommandRegistry;
use App\Console\Commands\MigrateBackOfficeAssignmentEventsCommand;
use App\Domain\EventSourcing\Rebuild\LegacyBackOfficeAssignmentDetector;
use App\Models\AdminCommandRun;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Feature\EventSourcing\Concerns\BuildsReadModelRebuildFixtures;
use Tests\TestCase;

/**
 * 旧形式のバックオフィス割当イベントが残っている間の、ReadModelの作り直し(read-models:rebuild・projections:rebuild)の拒否と、
 * 運用コマンド一覧への登録(docs/changesets/20261122-backoffice-assignment-migration-admin/spec.md 論点2・5)。
 */
class LegacyBackOfficeAssignmentGuardTest extends TestCase
{
    use BuildsReadModelRebuildFixtures;
    use DatabaseMigrations;

    private const TASK = '11111111-1111-4111-8111-111111111111';

    private int $version = 0;

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

    public function test_the_migration_is_listed_as_an_admin_command_with_only_the_dry_run_option(): void
    {
        $metadata = app(AdminCommandRegistry::class)->find('backoffice:migrate-assignment-events');

        $this->assertNotNull($metadata);
        $this->assertSame('バックオフィス割当イベントの移行', $metadata['label']);
        $this->assertSame(['dry-run'], collect($metadata['parameters'])->pluck('name')->all());
        $this->assertSame('option', $metadata['parameters'][0]['kind']);
        $this->assertFalse($metadata['parameters'][0]['accepts_value']);
        foreach (['メンテナンス', '1:00〜2:20', '試し実行', '503'] as $word) {
            $this->assertStringContainsString($word, $metadata['description']);
        }
        $this->assertInstanceOf(MigrateBackOfficeAssignmentEventsCommand::class, app(Kernel::class)->all()['backoffice:migrate-assignment-events']);
        $this->assertArrayNotHasKey('backoffice:rebuild-assignment-events', app(Kernel::class)->all());
    }

    public function test_the_detector_counts_only_assigned_events_without_previous_assigned_user_id(): void
    {
        $detector = new LegacyBackOfficeAssignmentDetector;
        $this->assertSame(0, $detector->count());

        $this->insertAssigned(['assignedUserId' => 'a', 'assignedByUserId' => 'm', 'previousAssignedUserId' => null]);
        $this->insertAssigned(['assignedUserId' => 'a', 'assignedByUserId' => 'm', 'previousAssignedUserId' => 'b']);
        $this->assertSame(0, $detector->count(), '新形式(null も含む)は数えない');

        $this->insertAssigned(['assignedUserId' => 'a', 'assignedByUserId' => 'm']);
        $this->insertAssigned(['assignedUserId' => 'c', 'assignedByUserId' => 'm']);
        $this->assertSame(2, $detector->count());
    }

    public function test_read_models_rebuild_is_refused_while_legacy_assigned_events_remain(): void
    {
        $this->insertAssigned(['assignedUserId' => 'a', 'assignedByUserId' => 'm']);
        $stray = User::factory()->create();
        $events = DB::table('stored_events')->count();

        $this->artisan('read-models:rebuild')
            ->expectsOutputToContain('旧形式のバックオフィス割当イベントが1件あります。先に運用コマンド『バックオフィス割当イベントの移行』を実行してください。')
            ->assertExitCode(1);

        $this->assertTrue(DB::table('users')->where('id', $stray->id)->exists(), 'ReadModel は作り直されない');
        $this->assertSame($events, DB::table('stored_events')->count());
        $this->assertSame(0, AdminCommandRun::query()->where('command_name', 'read-models:rebuild')->count());
    }

    public function test_a_queued_read_models_rebuild_fails_with_the_message_recorded(): void
    {
        $admin = $this->createEventAdmin();
        $this->insertAssigned(['assignedUserId' => 'a', 'assignedByUserId' => 'm']);
        $run = AdminCommandRun::query()->create([
            'command_name' => 'read-models:rebuild', 'parameters' => ['dry-run' => false], 'status' => 'queued', 'requested_by_user_id' => $admin->id,
        ]);

        $this->artisan('admin-commands:run-pending')->assertExitCode(0);

        $fresh = $run->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame(1, $fresh->exit_code);
        $this->assertStringContainsString('バックオフィス割当イベントの移行', $fresh->error_message);
    }

    public function test_read_models_rebuild_dry_run_only_warns(): void
    {
        $this->insertAssigned(['assignedUserId' => 'a', 'assignedByUserId' => 'm']);

        $this->artisan('read-models:rebuild', ['--dry-run' => true])
            ->expectsOutputToContain('警告: 旧形式のバックオフィス割当イベントが1件あります')
            ->assertExitCode(0);
    }

    public function test_projections_rebuild_is_refused_while_legacy_assigned_events_remain(): void
    {
        $this->insertAssigned(['assignedUserId' => 'a', 'assignedByUserId' => 'm']);

        $this->artisan('projections:rebuild')
            ->expectsOutputToContain('先に運用コマンド『バックオフィス割当イベントの移行』を実行してください。')
            ->assertExitCode(1);
    }

    public function test_after_the_migration_both_rebuilds_run_as_usual(): void
    {
        $user = $this->createEventUser('Assignee');
        $this->insertCreated();
        $this->insertAssigned(['assignedUserId' => $user->id, 'assignedByUserId' => $user->id]);
        $this->artisan('read-models:rebuild')->assertExitCode(1);

        $this->artisan('backoffice:migrate-assignment-events')->assertExitCode(0);

        $this->assertSame(0, (new LegacyBackOfficeAssignmentDetector)->count());
        $this->artisan('read-models:rebuild', ['--dry-run' => true])->assertExitCode(0);
        $this->artisan('read-models:rebuild')->assertExitCode(0);
        $this->artisan('projections:rebuild')->assertExitCode(0);
    }

    private function insertCreated(): void
    {
        $this->insert('backoffice_task.created', ['sourceType' => 'workflow_request', 'sourceId' => 'w', 'taskType' => 'general', 'title' => 't', 'assignedDepartment' => null, 'dueOn' => null]);
    }

    /** @param  array<string, mixed>  $payload */
    private function insertAssigned(array $payload): void
    {
        $this->insert('backoffice_task.assigned', $payload);
    }

    /** @param  array<string, mixed>  $payload */
    private function insert(string $class, array $payload): void
    {
        $this->version++;
        $id = (int) DB::table('stored_events')->max('id') + 1;
        DB::table('stored_events')->insert([
            'id' => $id, 'aggregate_uuid' => self::TASK, 'aggregate_version' => $this->version, 'event_version' => 1,
            'event_class' => $class, 'event_properties' => json_encode($payload),
            'meta_data' => json_encode(['aggregate-root-uuid' => self::TASK, 'aggregate-root-version' => $this->version, 'stored-event-id' => $id, 'created-at' => '2026-09-01 10:00:00']),
            'created_at' => '2026-09-01 10:00:00',
        ]);
    }
}
