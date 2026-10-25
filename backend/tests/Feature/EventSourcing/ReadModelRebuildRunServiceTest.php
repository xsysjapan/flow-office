<?php

namespace Tests\Feature\EventSourcing;

use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use App\Domain\ReadModelRebuild\Aggregates\ReadModelRebuildAuditAggregate;
use App\Models\AdminCommandRun;
use App\Models\User;
use App\Services\EventSourcing\ReadModelRebuildRunService;
use App\Services\PaidLeave\ScheduleAssessmentRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 作り直しの記録(`admin_command_runs`)の作成・取り出し・固まった記録の扱い・メンテナンス判定・監査イベントのテスト
 * (docs/changesets/20261025-admin-read-model-rebuild/spec.md 論点3・6・7)。
 * 作り直し自体(全テーブルを空にして再生)は`ReadModelRebuilderTest`・`ReadModelRebuildCommandTest`。
 */
class ReadModelRebuildRunServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ReadModelRebuilder::clearHeartbeat();
    }

    protected function tearDown(): void
    {
        ReadModelRebuilder::clearHeartbeat();
        parent::tearDown();
    }

    private function service(): ReadModelRebuildRunService
    {
        return app(ReadModelRebuildRunService::class);
    }

    private function makeRun(string $status, array $attributes = []): AdminCommandRun
    {
        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);
        $run = AdminCommandRun::query()->create([
            'command_name' => ReadModelRebuildRunService::COMMAND_NAME,
            'parameters' => ['dry_run' => false],
            'status' => $status,
            'requested_by_user_id' => User::factory()->create()->id,
            ...$attributes,
        ]);
        if ($createdAt !== null) {
            // created_atは一括代入の対象外なので、クエリで直接設定する。
            AdminCommandRun::query()->whereKey($run->id)->toBase()->update(['created_at' => $createdAt]);
            $run = $run->fresh();
        }

        return $run;
    }

    private function assessmentRun(string $status): AdminCommandRun
    {
        return AdminCommandRun::query()->create([
            'command_name' => ScheduleAssessmentRunService::COMMAND_NAME,
            'parameters' => ['trigger' => 'manual'],
            'status' => $status,
            'requested_by_user_id' => User::factory()->create()->id,
        ]);
    }

    private function oldHeartbeat(): void
    {
        Cache::store('file')->put(ReadModelRebuilder::HEARTBEAT_KEY, now()->subHour()->getTimestamp(), 600);
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

    public function test_create_run_records_a_queued_run_and_the_requested_audit_event(): void
    {
        $user = User::factory()->create();

        $run = $this->service()->createRun($user, true);

        $this->assertNotNull($run);
        $this->assertSame('queued', $run->status);
        $this->assertSame(['dry_run' => true], $run->parameters);
        $this->assertSame($user->id, $run->requested_by_user_id);
        $events = $this->auditEvents();
        $this->assertCount(1, $events);
        $this->assertSame('read_model_rebuild.requested', $events[0]['class']);
        $this->assertSame($run->id, $events[0]['properties']['runId']);
        $this->assertSame($user->id, $events[0]['properties']['requestedByUserId']);
        $this->assertTrue($events[0]['properties']['dryRun']);
    }

    public function test_a_second_request_is_refused_while_a_run_is_queued_or_running(): void
    {
        $user = User::factory()->create();
        $this->assertNotNull($this->service()->createRun($user, false));

        $this->assertNull($this->service()->createRun($user, false));
        $this->assertSame(1, AdminCommandRun::query()->where('command_name', ReadModelRebuildRunService::COMMAND_NAME)->count());
        $this->assertCount(1, $this->auditEvents());

        $this->service()->claimPendingRun();
        $this->assertNull($this->service()->createRun($user, true));
    }

    public function test_a_request_is_accepted_again_after_the_previous_run_finished(): void
    {
        $user = User::factory()->create();
        $first = $this->service()->createRun($user, false);
        $claimed = $this->service()->claimPendingRun();
        $this->service()->markSucceeded($claimed, ['dry_run' => false, 'tables' => [], 'changed_tables' => 0]);

        $second = $this->service()->createRun($user, true);

        $this->assertNotNull($second);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_claim_moves_the_oldest_queued_run_to_running_and_writes_a_heartbeat(): void
    {
        $older = $this->makeRun('queued', ['created_at' => now()->subMinutes(5)]);
        $this->makeRun('queued', ['created_at' => now()->subMinutes(1)]);

        $claimed = $this->service()->claimPendingRun();

        $this->assertSame($older->id, $claimed->id);
        $this->assertSame('running', $claimed->fresh()->status);
        $this->assertNotNull($claimed->fresh()->started_at);
        $this->assertNotNull(ReadModelRebuilder::lastHeartbeat());
        $this->assertTrue($this->service()->isRunning());
    }

    public function test_claim_returns_null_when_nothing_is_queued_or_a_run_is_already_running(): void
    {
        $this->assertNull($this->service()->claimPendingRun());

        $this->makeRun('running', ['started_at' => now()]);
        $this->makeRun('queued');
        $this->assertNull($this->service()->claimPendingRun());
    }

    public function test_claim_does_not_take_a_run_while_a_paid_leave_assessment_is_queued_or_running(): void
    {
        $queued = $this->makeRun('queued');
        $assessment = $this->assessmentRun('running');

        $this->assertNull($this->service()->claimPendingRun());
        $this->assertSame('queued', $queued->fresh()->status);

        $assessment->update(['status' => 'queued']);
        $this->assertNull($this->service()->claimPendingRun());

        $assessment->update(['status' => 'succeeded']);
        $this->assertNotNull($this->service()->claimPendingRun());
    }

    public function test_succeeded_run_stores_the_summary_clears_the_heartbeat_and_records_the_finished_event(): void
    {
        $user = User::factory()->create();
        $this->service()->createRun($user, true);
        $run = $this->service()->claimPendingRun();
        $summary = ['dry_run' => true, 'tables' => [['table' => 'groups', 'before' => 1, 'after' => 1, 'changed' => false]], 'changed_tables' => 3];

        $this->service()->markSucceeded($run, $summary);

        $fresh = $run->fresh();
        $this->assertSame('succeeded', $fresh->status);
        $this->assertSame($summary, $fresh->summary);
        $this->assertNotNull($fresh->finished_at);
        $this->assertNull(ReadModelRebuilder::lastHeartbeat());
        $this->assertFalse($this->service()->isRunning());
        $events = $this->auditEvents();
        $this->assertSame(['read_model_rebuild.requested', 'read_model_rebuild.finished'], array_column($events, 'class'));
        $this->assertSame(['runId' => $run->id, 'result' => 'succeeded', 'dryRun' => true, 'changedTables' => 3], $events[1]['properties']);
    }

    public function test_failed_run_stores_the_error_message_and_records_the_finished_event(): void
    {
        $user = User::factory()->create();
        $this->service()->createRun($user, false);
        $run = $this->service()->claimPendingRun();

        $this->service()->markFailed($run, '何かが壊れた');

        $fresh = $run->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame('何かが壊れた', $fresh->error_message);
        $this->assertFalse($this->service()->isRunning());
        $events = $this->auditEvents();
        $this->assertSame('read_model_rebuild.finished', $events[1]['class']);
        $this->assertSame('failed', $events[1]['properties']['result']);
        $this->assertFalse($events[1]['properties']['dryRun']);
        $this->assertSame(0, $events[1]['properties']['changedTables']);
    }

    public function test_running_run_with_a_stale_heartbeat_is_failed_and_the_maintenance_is_released(): void
    {
        $run = $this->makeRun('running', ['started_at' => now()->subHour(), 'created_at' => now()->subHour()]);
        $this->oldHeartbeat();

        $this->assertFalse($this->service()->isRunning());
        $this->assertSame(1, $this->service()->failStaleRuns());

        $fresh = $run->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame(ReadModelRebuildRunService::STALE_MESSAGE, $fresh->error_message);
        $this->assertSame(['read_model_rebuild.finished'], array_column($this->auditEvents(), 'class'));
    }

    public function test_running_run_with_a_fresh_heartbeat_is_not_failed_even_if_it_started_long_ago(): void
    {
        $run = $this->makeRun('running', ['started_at' => now()->subHours(2)]);
        ReadModelRebuilder::heartbeat();

        $this->assertTrue($this->service()->isRunning());
        $this->assertSame(0, $this->service()->failStaleRuns());
        $this->assertSame('running', $run->fresh()->status);
    }

    public function test_heartbeat_age_threshold_is_configurable(): void
    {
        $this->makeRun('running', ['started_at' => now()->subMinutes(3)]);
        Cache::store('file')->put(ReadModelRebuilder::HEARTBEAT_KEY, now()->subMinutes(3)->getTimestamp(), 600);

        $this->assertTrue($this->service()->isRunning());

        config(['read_model_rebuild.heartbeat_stale_minutes' => 2]);
        $this->assertFalse($this->service()->isRunning());
    }

    public function test_queued_run_older_than_the_timeout_is_failed_unless_an_assessment_is_active(): void
    {
        $run = $this->makeRun('queued', ['created_at' => now()->subMinutes(31)]);
        $assessment = $this->assessmentRun('running');

        $this->assertSame(0, $this->service()->failStaleRuns());
        $this->assertSame('queued', $run->fresh()->status);

        $assessment->update(['status' => 'succeeded']);
        $this->assertSame(1, $this->service()->failStaleRuns());
        $this->assertSame('failed', $run->fresh()->status);
    }

    public function test_recent_queued_run_is_not_failed(): void
    {
        $run = $this->makeRun('queued', ['created_at' => now()->subMinutes(29)]);

        $this->assertSame(0, $this->service()->failStaleRuns());
        $this->assertSame('queued', $run->fresh()->status);
    }

    public function test_is_running_is_false_for_queued_and_finished_runs_and_for_other_commands(): void
    {
        $this->makeRun('queued');
        $this->makeRun('succeeded', ['started_at' => now(), 'finished_at' => now()]);
        $this->makeRun('failed', ['started_at' => now(), 'finished_at' => now()]);
        $this->assessmentRun('running');
        ReadModelRebuilder::heartbeat();

        $this->assertFalse($this->service()->isRunning());
    }

    public function test_is_running_does_not_write_anything(): void
    {
        $run = $this->makeRun('running', ['started_at' => now()->subHour()]);
        $this->oldHeartbeat();
        $events = DB::table('stored_events')->count();

        $this->assertFalse($this->service()->isRunning());

        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame($events, DB::table('stored_events')->count());
    }
}
