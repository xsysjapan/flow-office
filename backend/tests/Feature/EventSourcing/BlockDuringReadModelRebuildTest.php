<?php

namespace Tests\Feature\EventSourcing;

use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use App\Models\AdminCommandRun;
use App\Models\Role;
use App\Models\User;
use App\Services\EventSourcing\ReadModelRebuildRunService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 作り直しの実行中のメンテナンス(全APIを503・除外のGETは通す・`GET /maintenance-status`・スケジュールの`skip`)のテスト
 * (docs/changesets/20261025-read-model-rebuild-admin-command/spec.md 論点3)。
 */
class BlockDuringReadModelRebuildTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'システムのメンテナンス(データの作り直し)中です。しばらくしてから再度お試しください。';

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
        $user = User::factory()->create();
        $this->assignRole($user, Role::query()->firstOrCreate(['code' => Role::ADMIN], ['name' => 'Admin', 'is_system' => true, 'status' => 'active']));

        return $user;
    }

    private function startMaintenance(User $requester): AdminCommandRun
    {
        $run = AdminCommandRun::query()->create([
            'command_name' => ReadModelRebuildRunService::COMMAND_NAME,
            'parameters' => ['dry_run' => false],
            'status' => 'running',
            'requested_by_user_id' => $requester->id,
            'started_at' => now(),
        ]);
        ReadModelRebuilder::heartbeat();

        return $run;
    }

    public function test_maintenance_status_is_false_normally_and_needs_no_authentication(): void
    {
        $this->getJson('/api/maintenance-status')->assertOk()->assertExactJson(['maintenance' => false]);
    }

    public function test_maintenance_status_is_true_while_a_run_is_running_with_a_fresh_heartbeat(): void
    {
        $this->startMaintenance($this->admin());

        $this->getJson('/api/maintenance-status')->assertOk()->assertExactJson(['maintenance' => true]);
    }

    public function test_normal_apis_return_503_with_the_maintenance_code_for_every_method(): void
    {
        $user = $this->admin();
        $this->startMaintenance($user);

        foreach ([
            ['getJson', '/api/users'],
            ['getJson', '/api/admin/system-settings'],
            ['postJson', '/api/admin/commands/read-models:rebuild/runs'],
            ['putJson', '/api/admin/system-settings'],
            ['deleteJson', '/api/admin/user-management/users/'.$user->id.'/groups/'.fake()->uuid()],
            ['postJson', '/api/attendance/clock-in'],
            ['postJson', '/api/auth/logout'],
        ] as [$method, $path]) {
            $this->actingAs($user)->{$method}($path, [])
                ->assertStatus(503)
                ->assertExactJson(['code' => 'maintenance', 'message' => self::MESSAGE], true);
        }
    }

    public function test_unauthenticated_and_write_requests_are_also_blocked(): void
    {
        $this->startMaintenance($this->admin());

        $this->getJson('/api/users')->assertStatus(503)->assertJsonPath('code', 'maintenance');
        $this->postJson('/api/auth/local-login', [])->assertStatus(503)->assertJsonPath('code', 'maintenance');
        $this->postJson('/api/onboarding/local', [])->assertStatus(503)->assertJsonPath('code', 'maintenance');
    }

    public function test_excluded_get_endpoints_still_work_during_the_maintenance(): void
    {
        $user = $this->admin();
        $this->startMaintenance($user);

        $this->getJson('/api/maintenance-status')->assertOk();
        $this->getJson('/api/onboarding/status')->assertOk();
        $this->actingAs($user)->getJson('/api/access/me')->assertOk();
        $this->actingAs($user)->getJson('/api/admin/commands')->assertOk();
        $this->actingAs($user)->getJson('/api/admin/command-runs')->assertOk()->assertJsonPath('data.0.status', 'running');
        $this->actingAs($user)->getJson('/api/auth/me')->assertStatus(200);
    }

    public function test_the_excluded_paths_are_only_open_for_get(): void
    {
        $user = $this->admin();
        $this->startMaintenance($user);

        // 運用コマンドの依頼(同じ画面のPOST)は止める。
        $this->actingAs($user)->postJson('/api/admin/commands/read-models:rebuild/runs', ['parameters' => ['dry-run' => true]])->assertStatus(503);
        $this->assertSame(1, AdminCommandRun::query()->count());
    }

    public function test_requests_pass_when_the_heartbeat_is_stale(): void
    {
        $user = $this->admin();
        AdminCommandRun::query()->create([
            'command_name' => ReadModelRebuildRunService::COMMAND_NAME,
            'parameters' => ['dry_run' => false],
            'status' => 'running',
            'requested_by_user_id' => $user->id,
            'started_at' => now()->subHour(),
        ]);
        Cache::store('file')->put(ReadModelRebuilder::HEARTBEAT_KEY, now()->subHour()->getTimestamp(), 600);

        $this->getJson('/api/maintenance-status')->assertOk()->assertExactJson(['maintenance' => false]);
        $this->actingAs($user)->getJson('/api/admin/system-settings')->assertOk();
    }

    public function test_requests_pass_when_the_run_is_queued_or_after_it_finished(): void
    {
        $user = $this->admin();
        $run = AdminCommandRun::query()->create([
            'command_name' => ReadModelRebuildRunService::COMMAND_NAME,
            'parameters' => ['dry_run' => false],
            'status' => 'queued',
            'requested_by_user_id' => $user->id,
        ]);
        ReadModelRebuilder::heartbeat();
        $this->actingAs($user)->getJson('/api/admin/system-settings')->assertOk();

        $run->update(['status' => 'running', 'started_at' => now()]);
        $this->actingAs($user)->getJson('/api/admin/system-settings')->assertStatus(503);

        app(ReadModelRebuildRunService::class)->markSucceeded($run, ['dry_run' => false, 'tables' => [], 'changed_tables' => 0]);
        $this->actingAs($user)->getJson('/api/admin/system-settings')->assertOk();
        $this->getJson('/api/maintenance-status')->assertExactJson(['maintenance' => false]);
    }

    public function test_every_schedule_including_the_runner_is_skipped_while_running(): void
    {
        $this->app->make(Kernel::class)->bootstrap();
        $events = collect($this->app->make(Schedule::class)->events());
        $this->assertGreaterThan(5, $events->count());
        $this->assertNull($events->first(fn ($event) => str_contains($event->command, 'read-models:rebuild')));
        $this->assertNotNull($events->first(fn ($event) => str_contains($event->command, 'admin-commands:run-pending')));

        $this->assertTrue($events->every(fn ($event) => $event->filtersPass($this->app)));

        $this->startMaintenance($this->admin());

        foreach ($events as $event) {
            $this->assertFalse($event->filtersPass($this->app), $event->command);
        }
    }
}
