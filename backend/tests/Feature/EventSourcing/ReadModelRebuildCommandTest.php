<?php

namespace Tests\Feature\EventSourcing;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Domain\PaidLeaveAccount\Commands\RevokePaidLeaveGrant;
use App\Domain\ReadModelRebuild\Aggregates\ReadModelRebuildAuditAggregate;
use App\Models\AdminCommandRun;
use App\Services\EventSourcing\ReadModelRebuildRunService;
use App\Services\PaidLeave\ScheduleAssessmentRunService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\EventSourcing\Concerns\BuildsReadModelRebuildFixtures;
use Tests\TestCase;

/**
 * `read-models:rebuild --pending`のシナリオテスト: 依頼(サービス)→取り出し→作り直し→記録・監査イベント
 * (docs/changesets/20261025-admin-read-model-rebuild/spec.md)。実際に全テーブルを空にして再生するので`DatabaseMigrations`。
 */
class ReadModelRebuildCommandTest extends TestCase
{
    use BuildsReadModelRebuildFixtures;
    use DatabaseMigrations;

    /** `migrate:rollback`は、SQLiteで`down()`が失敗する既存のマイグレーションがあるため行わない(ReadModelRebuilderTest参照)。 */
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

    private function brokenGrant(): array
    {
        $admin = $this->createEventAdmin();
        $user = $this->createEventUser('Employee C');
        $grantId = (string) Str::uuid();
        app(CommandBus::class)->dispatch(new GrantPaidLeave($user->id, '2026-04-01', '2028-03-31', 10.0, null, 'manual', $grantId));
        app(CommandBus::class)->dispatch(new RevokePaidLeaveGrant($user->id, $grantId, $admin->id, 'テスト'));
        DB::table('paid_leave_grants')->where('id', $grantId)->update(['remaining_days' => 10.0]);

        return [$admin, $grantId];
    }

    /** @return list<array{class: string, properties: array<string, mixed>}> */
    private function auditEvents(): array
    {
        return DB::table('stored_events')
            ->where('aggregate_uuid', ReadModelRebuildAuditAggregate::aggregateId())
            ->orderBy('id')
            ->get(['event_class', 'event_properties'])
            ->map(fn ($row) => ['class' => $row->event_class, 'properties' => json_decode($row->event_properties, true)])
            ->all();
    }

    public function test_pending_run_is_claimed_rebuilt_and_recorded_as_succeeded_with_both_audit_events(): void
    {
        [$admin, $grantId] = $this->brokenGrant();
        $run = app(ReadModelRebuildRunService::class)->createRun($admin, false);

        $this->artisan('read-models:rebuild', ['--pending' => true])->assertExitCode(0);

        $fresh = $run->fresh();
        $this->assertSame('succeeded', $fresh->status);
        $this->assertNotNull($fresh->started_at);
        $this->assertNotNull($fresh->finished_at);
        $this->assertFalse($fresh->summary['dry_run']);
        $this->assertSame(1, $fresh->summary['changed_tables']);
        $this->assertEqualsWithDelta(0.0, (float) DB::table('paid_leave_grants')->where('id', $grantId)->value('remaining_days'), 0.000001);
        $events = $this->auditEvents();
        $this->assertSame(['read_model_rebuild.requested', 'read_model_rebuild.finished'], array_column($events, 'class'));
        $this->assertSame(['runId' => $run->id, 'result' => 'succeeded', 'dryRun' => false, 'changedTables' => 1], $events[1]['properties']);
        $this->assertFalse(app(ReadModelRebuildRunService::class)->isRunning());
    }

    public function test_dry_run_request_leaves_the_data_unchanged_and_records_the_summary(): void
    {
        [$admin, $grantId] = $this->brokenGrant();
        $run = app(ReadModelRebuildRunService::class)->createRun($admin, true);

        $this->artisan('read-models:rebuild', ['--pending' => true])->assertExitCode(0);

        $fresh = $run->fresh();
        $this->assertSame('succeeded', $fresh->status);
        $this->assertTrue($fresh->summary['dry_run']);
        $this->assertSame(1, $fresh->summary['changed_tables']);
        $this->assertEqualsWithDelta(10.0, (float) DB::table('paid_leave_grants')->where('id', $grantId)->value('remaining_days'), 0.000001);
    }

    public function test_an_exception_marks_the_run_failed_with_the_message_and_keeps_the_original_data(): void
    {
        [$admin, $grantId] = $this->brokenGrant();
        $run = app(ReadModelRebuildRunService::class)->createRun($admin, false);
        config(['read_model_rebuild.time_limit_minutes' => 0]);

        $this->artisan('read-models:rebuild', ['--pending' => true])->assertExitCode(1);

        $fresh = $run->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame(ReadModelRebuilder::TIME_LIMIT_MESSAGE, $fresh->error_message);
        $this->assertNull($fresh->summary);
        $this->assertEqualsWithDelta(10.0, (float) DB::table('paid_leave_grants')->where('id', $grantId)->value('remaining_days'), 0.000001);
        $events = $this->auditEvents();
        $this->assertSame('failed', $events[1]['properties']['result']);
        $this->assertFalse(app(ReadModelRebuildRunService::class)->isRunning());
        // 失敗の後は、新しい依頼を受け付ける。
        $this->assertNotNull(app(ReadModelRebuildRunService::class)->createRun($admin, true));
    }

    public function test_pending_run_waits_while_an_assessment_run_is_active(): void
    {
        [$admin, $grantId] = $this->brokenGrant();
        $run = app(ReadModelRebuildRunService::class)->createRun($admin, false);
        AdminCommandRun::query()->create([
            'command_name' => ScheduleAssessmentRunService::COMMAND_NAME,
            'parameters' => ['trigger' => 'manual'],
            'status' => 'running',
            'requested_by_user_id' => $admin->id,
            'started_at' => now(),
        ]);

        $this->artisan('read-models:rebuild', ['--pending' => true])->assertExitCode(0);

        $this->assertSame('queued', $run->fresh()->status);
        $this->assertEqualsWithDelta(10.0, (float) DB::table('paid_leave_grants')->where('id', $grantId)->value('remaining_days'), 0.000001);
    }

    public function test_without_pending_the_command_refuses_to_run(): void
    {
        $this->artisan('read-models:rebuild')->assertExitCode(1);
    }

    public function test_with_nothing_queued_the_command_does_nothing(): void
    {
        $this->artisan('read-models:rebuild', ['--pending' => true])->assertExitCode(0);

        $this->assertSame(0, AdminCommandRun::query()->where('command_name', ReadModelRebuildRunService::COMMAND_NAME)->count());
    }
}
