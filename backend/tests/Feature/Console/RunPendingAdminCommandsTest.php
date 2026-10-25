<?php

namespace Tests\Feature\Console;

use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use App\Models\AdminCommandRun;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminCommand\AdminCommandRunner;
use App\Services\EventSourcing\ReadModelRebuildRunService;
use App\Services\PaidLeave\ScheduleAssessmentRunService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 運用コマンドの実行経路: 依頼(`queued`を作るだけ)→ 実行役`admin-commands:run-pending` → 結果
 * (docs/changesets/20261025-read-model-rebuild-admin-command/spec.md 論点1)。
 */
class RunPendingAdminCommandsTest extends TestCase
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

    private function admin(): User
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->firstOrCreate(['code' => Role::ADMIN], ['name' => 'Admin']));

        return $admin;
    }

    private function queue(string $command, array $parameters = [], ?string $createdAt = null): AdminCommandRun
    {
        $run = AdminCommandRun::query()->create([
            'command_name' => $command,
            'parameters' => $parameters,
            'status' => 'queued',
            'requested_by_user_id' => User::factory()->create()->id,
        ]);
        if ($createdAt !== null) {
            AdminCommandRun::query()->whereKey($run->id)->toBase()->update(['created_at' => $createdAt]);
        }

        return $run->fresh();
    }

    public function test_a_request_only_creates_a_queued_run_and_pushes_no_job(): void
    {
        Queue::fake();

        $this->actingAs($this->admin())->postJson('/api/admin/commands/attendance:rebuild-calculation-projections/runs', ['parameters' => []])
            ->assertAccepted()->assertJsonPath('data.status', 'queued');

        Queue::assertNothingPushed();
        $this->assertSame(1, AdminCommandRun::query()->where('status', 'queued')->count());
    }

    public function test_the_runner_executes_a_queued_run_and_records_the_result(): void
    {
        $run = $this->queue('attendance:rebuild-calculation-projections');

        $this->assertSame(0, Artisan::call('admin-commands:run-pending'));

        $fresh = $run->fresh();
        $this->assertSame('succeeded', $fresh->status);
        $this->assertSame(0, $fresh->exit_code);
        $this->assertNotNull($fresh->started_at);
        $this->assertNotNull($fresh->finished_at);
    }

    public function test_a_run_with_options_passes_them_to_the_command(): void
    {
        $run = $this->queue('attendance:migrate-work-classifications', ['year-month' => '2026-07']);

        $this->assertSame(0, Artisan::call('admin-commands:run-pending'));

        $fresh = $run->fresh();
        $this->assertSame('succeeded', $fresh->status);
        $this->assertSame(0, $fresh->exit_code);
    }

    public function test_an_exception_fails_only_that_run_and_the_next_run_is_executed(): void
    {
        $broken = $this->queue('attendance:rebuild-calculation-projections', ['undefined-parameter' => 1], now()->subMinutes(2)->toDateTimeString());
        $next = $this->queue('attendance:rebuild-calculation-projections', [], now()->subMinute()->toDateTimeString());

        $this->assertSame(0, Artisan::call('admin-commands:run-pending'));

        $this->assertSame('failed', $broken->fresh()->status);
        $this->assertNotNull($broken->fresh()->error_message);
        $this->assertNotNull($broken->fresh()->finished_at);
        $this->assertSame('succeeded', $next->fresh()->status);
    }

    public function test_all_queued_runs_are_executed_oldest_first_in_one_start(): void
    {
        $second = $this->queue('attendance:rebuild-calculation-projections', [], now()->subMinute()->toDateTimeString());
        $first = $this->queue('attendance:rebuild-calculation-projections', [], now()->subMinutes(3)->toDateTimeString());

        $this->assertSame($first->id, app(AdminCommandRunner::class)->nextQueuedRun()->id);
        $this->assertSame(0, Artisan::call('admin-commands:run-pending'));

        $this->assertSame('succeeded', $first->fresh()->status);
        $this->assertSame('succeeded', $second->fresh()->status);
        $this->assertTrue($first->fresh()->started_at <= $second->fresh()->started_at);
    }

    public function test_an_unpublished_command_is_failed_and_other_commands_queued_runs_are_not_touched(): void
    {
        $unknown = $this->queue('migrate:fresh');
        // 別の経路(`paid-leave:assess-schedules --pending`)で動く記録は、実行役が触らない。
        $assessmentQueued = $this->queue(ScheduleAssessmentRunService::COMMAND_NAME, ['trigger' => 'manual']);
        $assessmentRunning = AdminCommandRun::query()->create([
            'command_name' => ScheduleAssessmentRunService::COMMAND_NAME,
            'parameters' => ['trigger' => 'schedule'],
            'status' => 'running',
            'started_at' => now(),
        ]);

        $this->assertSame(0, Artisan::call('admin-commands:run-pending'));

        // `migrate:fresh`は公開されていないので、実行役の対象外(queuedのまま実行されない)。
        $this->assertSame('queued', $unknown->fresh()->status);
        $this->assertSame('queued', $assessmentQueued->fresh()->status);
        $this->assertSame('running', $assessmentRunning->fresh()->status);
    }

    public function test_running_runs_left_at_start_are_failed_and_their_locks_are_released(): void
    {
        $stale = AdminCommandRun::query()->create([
            'command_name' => 'attendance:recalculate-days',
            'parameters' => [],
            'status' => 'running',
            'requested_by_user_id' => User::factory()->create()->id,
            'started_at' => now()->subMinute(),
        ]);
        $lock = Cache::lock('admin-command:attendance:recalculate-days', 14400);
        $this->assertTrue($lock->get());

        $this->assertSame(0, Artisan::call('admin-commands:run-pending'));

        $fresh = $stale->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame(AdminCommandRunner::STALE_MESSAGE, $fresh->error_message);
        $this->assertNotNull($fresh->finished_at);
        $this->assertTrue(Cache::lock('admin-command:attendance:recalculate-days', 10)->get());
    }

    public function test_a_stale_run_does_not_block_a_new_run_of_the_same_command(): void
    {
        AdminCommandRun::query()->create([
            'command_name' => 'attendance:rebuild-calculation-projections',
            'parameters' => [],
            'status' => 'running',
            'started_at' => now()->subMinute(),
        ]);
        Cache::lock('admin-command:attendance:rebuild-calculation-projections', 14400)->get();
        $next = $this->queue('attendance:rebuild-calculation-projections');

        $this->assertSame(0, Artisan::call('admin-commands:run-pending'));

        $this->assertSame('succeeded', $next->fresh()->status);
    }

    public function test_a_run_fails_when_the_same_command_holds_the_lock(): void
    {
        Cache::lock('admin-command:attendance:rebuild-calculation-projections', 14400)->get();
        $run = $this->queue('attendance:rebuild-calculation-projections');

        app(AdminCommandRunner::class)->execute($run);

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('同じコマンドが既に実行中です。', $run->fresh()->error_message);
    }

    public function test_the_rebuild_run_stays_queued_while_an_assessment_is_active_but_other_runs_are_executed(): void
    {
        $rebuild = $this->queue('read-models:rebuild', ['dry-run' => false], now()->subMinutes(5)->toDateTimeString());
        $other = $this->queue('attendance:rebuild-calculation-projections', [], now()->subMinute()->toDateTimeString());
        AdminCommandRun::query()->create([
            'command_name' => ScheduleAssessmentRunService::COMMAND_NAME,
            'parameters' => ['trigger' => 'manual'],
            'status' => 'queued',
            'requested_by_user_id' => User::factory()->create()->id,
        ]);

        $this->assertSame(0, Artisan::call('admin-commands:run-pending'));

        $this->assertSame('queued', $rebuild->fresh()->status);
        $this->assertNull($rebuild->fresh()->started_at);
        $this->assertSame('succeeded', $other->fresh()->status);
    }

    public function test_the_runner_schedule_is_every_minute_in_background_with_a_240_minute_overlap_lock_and_is_skipped_during_the_rebuild(): void
    {
        $this->app->make(Kernel::class)->bootstrap();
        $event = collect($this->app->make(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'admin-commands:run-pending'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(240, $event->expiresAt);
        $this->assertTrue($event->runInBackground);
        $this->assertTrue($event->filtersPass($this->app));

        AdminCommandRun::query()->create([
            'command_name' => ReadModelRebuildRunService::COMMAND_NAME,
            'parameters' => ['dry_run' => false],
            'status' => 'running',
            'started_at' => now(),
        ]);
        ReadModelRebuilder::heartbeat();

        $this->assertFalse($event->filtersPass($this->app));
    }

    public function test_the_queue_worker_job_class_is_gone(): void
    {
        $this->assertFileDoesNotExist(app_path('Jobs/RunAdminCommandJob.php'));
    }
}
