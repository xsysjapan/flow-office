<?php

namespace Tests\Feature\EventSourcing;

use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use App\Models\AdminCommandRun;
use App\Models\User;
use App\Services\EventSourcing\ReadModelRebuildRunService;
use App\Services\PaidLeave\ScheduleAssessmentRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 作り直しの記録(`admin_command_runs`)のうち、メンテナンス判定・判定の実行中の確認・CLI直接実行の記録のテスト
 * (docs/changesets/20261025-read-model-rebuild-admin-command/spec.md 論点2・3)。
 * 作り直し自体は`ReadModelRebuilderTest`・`ReadModelRebuildCommandTest`、実行役は`RunPendingAdminCommandsTest`。
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
        return AdminCommandRun::query()->create([
            'command_name' => ReadModelRebuildRunService::COMMAND_NAME,
            'parameters' => ['dry_run' => false],
            'status' => $status,
            'requested_by_user_id' => User::factory()->create()->id,
            ...$attributes,
        ]);
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

    public function test_start_direct_run_creates_a_running_run_without_requester_and_starts_the_maintenance(): void
    {
        $run = $this->service()->startDirectRun(true);

        $this->assertNotNull($run);
        $fresh = $run->fresh();
        $this->assertSame('read-models:rebuild', $fresh->command_name);
        $this->assertSame('running', $fresh->status);
        $this->assertNull($fresh->requested_by_user_id);
        $this->assertSame(['dry-run' => true], $fresh->parameters);
        $this->assertNotNull($fresh->started_at);
        $this->assertTrue($this->service()->isRunning());
    }

    public function test_start_direct_run_is_refused_while_an_assessment_is_queued_or_running(): void
    {
        $assessment = $this->assessmentRun('queued');
        $this->assertNull($this->service()->startDirectRun(false));
        $assessment->update(['status' => 'running']);
        $this->assertNull($this->service()->startDirectRun(false));
        $this->assertSame(0, AdminCommandRun::query()->where('command_name', ReadModelRebuildRunService::COMMAND_NAME)->count());

        $assessment->update(['status' => 'succeeded']);
        $this->assertNotNull($this->service()->startDirectRun(false));
    }

    public function test_start_direct_run_is_refused_while_a_rebuild_is_running(): void
    {
        $this->assertNotNull($this->service()->startDirectRun(false));

        $this->assertNull($this->service()->startDirectRun(false));
        $this->assertSame(1, AdminCommandRun::query()->where('command_name', ReadModelRebuildRunService::COMMAND_NAME)->count());
    }

    public function test_succeeded_stores_the_summary_and_ends_the_maintenance(): void
    {
        $run = $this->service()->startDirectRun(false);

        $this->service()->markSucceeded($run, ['dry_run' => false, 'tables' => [], 'changed_tables' => 0]);

        $fresh = $run->fresh();
        $this->assertSame('succeeded', $fresh->status);
        $this->assertSame(0, $fresh->exit_code);
        $this->assertSame(0, $fresh->summary['changed_tables']);
        $this->assertNotNull($fresh->finished_at);
        $this->assertNull(ReadModelRebuilder::lastHeartbeat());
        $this->assertFalse($this->service()->isRunning());
    }

    public function test_failed_stores_the_error_message_and_ends_the_maintenance(): void
    {
        $run = $this->service()->startDirectRun(false);

        $this->service()->markFailed($run, 'エラー');

        $fresh = $run->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame(1, $fresh->exit_code);
        $this->assertSame('エラー', $fresh->error_message);
        $this->assertFalse($this->service()->isRunning());
    }

    public function test_running_run_with_a_stale_heartbeat_is_not_maintenance(): void
    {
        $this->makeRun('running', ['started_at' => now()->subHour()]);
        $this->oldHeartbeat();

        $this->assertFalse($this->service()->isRunning());
    }

    public function test_running_run_with_a_fresh_heartbeat_is_maintenance_even_if_it_started_long_ago(): void
    {
        $this->makeRun('running', ['started_at' => now()->subHours(2)]);
        ReadModelRebuilder::heartbeat();

        $this->assertTrue($this->service()->isRunning());
    }

    public function test_heartbeat_age_threshold_is_configurable(): void
    {
        $this->makeRun('running', ['started_at' => now()->subMinutes(3)]);
        Cache::store('file')->put(ReadModelRebuilder::HEARTBEAT_KEY, now()->subMinutes(3)->getTimestamp(), 600);

        $this->assertTrue($this->service()->isRunning());

        config(['read_model_rebuild.heartbeat_stale_minutes' => 2]);
        $this->assertFalse($this->service()->isRunning());
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
