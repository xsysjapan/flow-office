<?php

namespace Tests\Feature\EventSourcing;

use App\Console\Commands\RebuildReadModelsCommand;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Domain\PaidLeaveAccount\Commands\RevokePaidLeaveGrant;
use App\Models\AdminCommandRun;
use App\Models\User;
use App\Services\EventSourcing\ReadModelRebuildRunService;
use App\Services\PaidLeave\ScheduleAssessmentRunService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\EventSourcing\Concerns\BuildsReadModelRebuildFixtures;
use Tests\TestCase;

/**
 * 運用コマンド`read-models:rebuild`のシナリオテスト: 実行役(`admin-commands:run-pending`)経由・CLIから直接の実行 →
 * 作り直し → 記録・出力・summary(docs/changesets/20261025-read-model-rebuild-admin-command/spec.md 論点2)。
 * 実際に全テーブルを空にして再生するので`DatabaseMigrations`。
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

    /** @return array{0: User, 1: string} 管理者と、壊した付与のID */
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

    private function queue(User $requester, bool $dryRun): AdminCommandRun
    {
        return AdminCommandRun::query()->create([
            'command_name' => ReadModelRebuildRunService::COMMAND_NAME,
            'parameters' => ['dry-run' => $dryRun],
            'status' => 'queued',
            'requested_by_user_id' => $requester->id,
        ]);
    }

    private function remaining(string $grantId): float
    {
        return (float) DB::table('paid_leave_grants')->where('id', $grantId)->value('remaining_days');
    }

    public function test_the_command_is_listed_as_an_admin_command_with_a_dry_run_option_and_a_cautionary_description(): void
    {
        $metadata = app(\App\Console\AdminCommandRegistry::class)->find('read-models:rebuild');

        $this->assertNotNull($metadata);
        $this->assertSame('データの作り直し(ReadModel)', $metadata['label']);
        $this->assertSame(['dry-run'], collect($metadata['parameters'])->pluck('name')->all());
        $this->assertStringContainsString('メンテナンス', $metadata['description']);
        $this->assertStringContainsString('1:00〜2:20', $metadata['description']);
        $this->assertStringContainsString('ロールバック', $metadata['description']);
        $this->assertInstanceOf(RebuildReadModelsCommand::class, app(\Illuminate\Contracts\Console\Kernel::class)->all()['read-models:rebuild']);
    }

    public function test_a_queued_run_is_rebuilt_by_the_runner_and_the_output_shows_the_table_and_the_summary_is_saved(): void
    {
        [$admin, $grantId] = $this->brokenGrant();
        $run = $this->queue($admin, false);

        $this->assertSame(0, Artisan::call('admin-commands:run-pending'));

        $fresh = $run->fresh();
        $this->assertSame('succeeded', $fresh->status);
        $this->assertSame(0, $fresh->exit_code);
        $this->assertNotNull($fresh->started_at);
        $this->assertNotNull($fresh->finished_at);
        $this->assertFalse($fresh->summary['dry_run']);
        $this->assertSame(1, $fresh->summary['changed_tables']);
        $this->assertEqualsWithDelta(0.0, $this->remaining($grantId), 0.000001);
        // 出力: 表の見出しと、変更のあったテーブルが先頭(変更の無いテーブルより前)。警告は利用者・権限のテーブルに変更が無いので出ない。
        $this->assertStringContainsString('| テーブル', $fresh->output);
        $this->assertStringContainsString('変更あり', $fresh->output);
        $this->assertStringNotContainsString('警告', $fresh->output);
        $this->assertLessThan(strpos($fresh->output, '| attendance_days'), strpos($fresh->output, '| paid_leave_grants'));
        $this->assertFalse(app(ReadModelRebuildRunService::class)->isRunning());
    }

    public function test_dry_run_leaves_the_data_unchanged_and_saves_the_summary_after_the_rollback(): void
    {
        [$admin, $grantId] = $this->brokenGrant();
        $run = $this->queue($admin, true);

        $this->assertSame(0, Artisan::call('admin-commands:run-pending'));

        $fresh = $run->fresh();
        $this->assertSame('succeeded', $fresh->status);
        $this->assertTrue($fresh->summary['dry_run']);
        $this->assertSame(1, $fresh->summary['changed_tables']);
        $this->assertEqualsWithDelta(10.0, $this->remaining($grantId), 0.000001);
        $this->assertTrue(DB::table('users')->where('id', $admin->id)->exists());
        // 記録(admin_command_runs)はロールバックの対象外で残る。
        $this->assertSame(1, AdminCommandRun::query()->where('command_name', 'read-models:rebuild')->count());
    }

    public function test_a_warning_comes_first_when_the_user_and_permission_tables_change(): void
    {
        [$admin] = $this->brokenGrant();
        // イベントを経ずに作られた利用者(作り直しで消える)。
        User::factory()->create();
        $run = $this->queue($admin, true);

        $this->assertSame(0, Artisan::call('admin-commands:run-pending'));

        $output = $run->fresh()->output;
        $this->assertStringContainsString('警告', $output);
        $this->assertStringContainsString('users', substr($output, strpos($output, '警告'), 200));
        $this->assertLessThan(strpos($output, '| テーブル'), strpos($output, '警告'));
        $this->assertLessThan(strpos($output, '| application_integrations'), strpos($output, '| users'));
        $usersRow = collect($run->fresh()->summary['tables'])->firstWhere('table', 'users');
        $this->assertTrue($usersRow['changed']);
        $this->assertSame($usersRow['before'] - 1, $usersRow['after']);
    }

    public function test_an_exception_fails_the_run_with_the_message_and_keeps_the_original_data(): void
    {
        [$admin, $grantId] = $this->brokenGrant();
        $run = $this->queue($admin, false);
        config(['read_model_rebuild.time_limit_minutes' => 0]);

        $this->assertSame(0, Artisan::call('admin-commands:run-pending'));

        $fresh = $run->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame(1, $fresh->exit_code);
        $this->assertSame(ReadModelRebuilder::TIME_LIMIT_MESSAGE, $fresh->error_message);
        $this->assertStringContainsString(ReadModelRebuilder::TIME_LIMIT_MESSAGE, $fresh->output);
        $this->assertNull($fresh->summary);
        $this->assertEqualsWithDelta(10.0, $this->remaining($grantId), 0.000001);
        $this->assertFalse(app(ReadModelRebuildRunService::class)->isRunning());
    }

    public function test_the_runner_leaves_the_rebuild_queued_while_an_assessment_run_is_active(): void
    {
        [$admin, $grantId] = $this->brokenGrant();
        $run = $this->queue($admin, false);
        $assessment = AdminCommandRun::query()->create([
            'command_name' => ScheduleAssessmentRunService::COMMAND_NAME,
            'parameters' => ['trigger' => 'manual'],
            'status' => 'running',
            'requested_by_user_id' => $admin->id,
            'started_at' => now(),
        ]);

        $this->assertSame(0, Artisan::call('admin-commands:run-pending'));

        $this->assertSame('queued', $run->fresh()->status);
        $this->assertEqualsWithDelta(10.0, $this->remaining($grantId), 0.000001);

        // 判定が終われば、次の起動で実行される。
        $assessment->update(['status' => 'succeeded']);
        $this->assertSame(0, Artisan::call('admin-commands:run-pending'));
        $this->assertSame('succeeded', $run->fresh()->status);
    }

    public function test_a_direct_cli_run_creates_its_own_run_record_and_finishes_it(): void
    {
        [, $grantId] = $this->brokenGrant();

        $this->artisan('read-models:rebuild')->assertExitCode(0);

        $run = AdminCommandRun::query()->where('command_name', 'read-models:rebuild')->sole();
        $this->assertSame('succeeded', $run->status);
        $this->assertNull($run->requested_by_user_id);
        $this->assertSame(['dry-run' => false], $run->parameters);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(1, $run->summary['changed_tables']);
        $this->assertEqualsWithDelta(0.0, $this->remaining($grantId), 0.000001);
        $this->assertFalse(app(ReadModelRebuildRunService::class)->isRunning());
    }

    public function test_a_direct_cli_dry_run_records_dry_run_and_keeps_the_data(): void
    {
        [, $grantId] = $this->brokenGrant();

        $this->artisan('read-models:rebuild', ['--dry-run' => true])->assertExitCode(0);

        $run = AdminCommandRun::query()->where('command_name', 'read-models:rebuild')->sole();
        $this->assertSame(['dry-run' => true], $run->parameters);
        $this->assertTrue($run->summary['dry_run']);
        $this->assertEqualsWithDelta(10.0, $this->remaining($grantId), 0.000001);
    }

    public function test_a_direct_cli_run_is_in_maintenance_while_it_is_running(): void
    {
        $this->brokenGrant();
        config(['read_model_rebuild.claim_wait_seconds' => 0]);
        // 作り直しの途中(Projectorのreplayの前=待ちの時点)の状態を、記録を作る処理で確かめる。
        $run = app(ReadModelRebuildRunService::class)->startDirectRun(false);

        $this->assertSame('running', $run->status);
        $this->assertTrue(app(ReadModelRebuildRunService::class)->isRunning());
        $this->getJson('/api/maintenance-status')->assertExactJson(['maintenance' => true]);
    }

    public function test_a_direct_cli_run_is_refused_with_exit_code_1_while_an_assessment_is_active(): void
    {
        [$admin, $grantId] = $this->brokenGrant();
        AdminCommandRun::query()->create([
            'command_name' => ScheduleAssessmentRunService::COMMAND_NAME,
            'parameters' => ['trigger' => 'manual'],
            'status' => 'queued',
            'requested_by_user_id' => $admin->id,
        ]);

        $this->artisan('read-models:rebuild')
            ->expectsOutputToContain('有給付与予定の判定の実行中です')
            ->assertExitCode(1);

        $this->assertSame(0, AdminCommandRun::query()->where('command_name', 'read-models:rebuild')->count());
        $this->assertEqualsWithDelta(10.0, $this->remaining($grantId), 0.000001);
    }

    public function test_a_direct_cli_run_is_refused_with_exit_code_1_while_a_rebuild_is_running(): void
    {
        [, $grantId] = $this->brokenGrant();
        $running = app(ReadModelRebuildRunService::class)->startDirectRun(false);

        $this->artisan('read-models:rebuild')
            ->expectsOutputToContain('作り直しを実行中です')
            ->assertExitCode(1);

        $this->assertSame(1, AdminCommandRun::query()->where('command_name', 'read-models:rebuild')->count());
        $this->assertSame('running', $running->fresh()->status);
        $this->assertEqualsWithDelta(10.0, $this->remaining($grantId), 0.000001);
    }

    public function test_a_direct_cli_failure_marks_its_own_record_failed_with_exit_code_1(): void
    {
        [, $grantId] = $this->brokenGrant();
        config(['read_model_rebuild.time_limit_minutes' => 0]);

        $this->artisan('read-models:rebuild')
            ->expectsOutputToContain(ReadModelRebuilder::TIME_LIMIT_MESSAGE)
            ->assertExitCode(1);

        $run = AdminCommandRun::query()->where('command_name', 'read-models:rebuild')->sole();
        $this->assertSame('failed', $run->status);
        $this->assertSame(ReadModelRebuilder::TIME_LIMIT_MESSAGE, $run->error_message);
        $this->assertEqualsWithDelta(10.0, $this->remaining($grantId), 0.000001);
        $this->assertFalse(app(ReadModelRebuildRunService::class)->isRunning());
    }
}
