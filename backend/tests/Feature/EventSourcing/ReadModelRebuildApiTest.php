<?php

namespace Tests\Feature\EventSourcing;

use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use App\Models\AdminCommandRun;
use App\Models\Role;
use App\Models\User;
use App\Services\EventSourcing\ReadModelRebuildRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `/api/admin/read-model-rebuilds`のAPIテスト(権限`read_model.rebuild`・202/409・一覧と1件の形)
 * (docs/changesets/20261025-admin-read-model-rebuild/spec.md 論点5)。
 */
class ReadModelRebuildApiTest extends TestCase
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
        $user = User::factory()->create(['name' => '管理者A']);
        $this->assignRole($user, Role::query()->firstOrCreate(['code' => Role::ADMIN], ['name' => 'Admin', 'is_system' => true, 'status' => 'active']));

        return $user;
    }

    private function outsider(): User
    {
        $this->admin(); // 権限の行(permissions)を用意する。

        return User::factory()->create();
    }

    public function test_a_user_without_the_permission_gets_403_on_all_three_endpoints(): void
    {
        $user = $this->outsider();

        $this->actingAs($user)->getJson('/api/admin/read-model-rebuilds')->assertForbidden();
        $this->actingAs($user)->getJson('/api/admin/read-model-rebuilds/'.fake()->uuid())->assertForbidden();
        $this->actingAs($user)->postJson('/api/admin/read-model-rebuilds', ['dry_run' => true])->assertForbidden();
        $this->assertSame(0, AdminCommandRun::query()->count());
    }

    public function test_an_unauthenticated_request_gets_401(): void
    {
        $this->getJson('/api/admin/read-model-rebuilds')->assertUnauthorized();
        $this->postJson('/api/admin/read-model-rebuilds', ['dry_run' => true])->assertUnauthorized();
    }

    public function test_post_returns_202_with_a_queued_run(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson('/api/admin/read-model-rebuilds', ['dry_run' => true]);

        $response->assertStatus(202)
            ->assertJsonPath('data.dry_run', true)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.requested_by', ['id' => $admin->id, 'name' => '管理者A'])
            ->assertJsonPath('data.started_at', null)
            ->assertJsonPath('data.finished_at', null)
            ->assertJsonPath('data.error_message', null)
            ->assertJsonPath('data.summary', null);
        $this->assertNotNull($response->json('data.requested_at'));
        $run = AdminCommandRun::query()->findOrFail($response->json('data.id'));
        $this->assertSame(ReadModelRebuildRunService::COMMAND_NAME, $run->command_name);
        $this->assertSame(['dry_run' => true], $run->parameters);
        $this->assertDatabaseHas('stored_events', ['event_class' => 'read_model_rebuild.requested']);
    }

    public function test_post_with_dry_run_false_records_a_real_run(): void
    {
        $response = $this->actingAs($this->admin())->postJson('/api/admin/read-model-rebuilds', ['dry_run' => false]);

        $response->assertStatus(202)->assertJsonPath('data.dry_run', false);
    }

    public function test_post_requires_a_boolean_dry_run(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/read-model-rebuilds', [])->assertStatus(422)->assertJsonValidationErrors('dry_run');
        $this->actingAs($admin)->postJson('/api/admin/read-model-rebuilds', ['dry_run' => 'maybe'])->assertStatus(422)->assertJsonValidationErrors('dry_run');
        $this->assertSame(0, AdminCommandRun::query()->count());
    }

    public function test_post_returns_409_while_a_run_is_queued_or_running(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/api/admin/read-model-rebuilds', ['dry_run' => true])->assertStatus(202);

        $this->actingAs($admin)->postJson('/api/admin/read-model-rebuilds', ['dry_run' => false])
            ->assertStatus(409)
            ->assertExactJson(['message' => '作り直しを実行中・待機中です。終わってから実行してください。']);

        // `running`の間はメンテナンスで全API(依頼のAPIを含む)が503になる(BlockDuringReadModelRebuildTest)。
        // 心拍が無い(古い)`running`の記録は失敗とみなされ、新しい依頼を受け付ける(ReadModelRebuildRunServiceTest)。
        $this->assertSame(1, AdminCommandRun::query()->count());
    }

    public function test_index_lists_the_latest_20_runs_newest_first_without_summary(): void
    {
        $admin = $this->admin();
        for ($i = 0; $i < 22; $i++) {
            $run = AdminCommandRun::query()->create([
                'command_name' => ReadModelRebuildRunService::COMMAND_NAME,
                'parameters' => ['dry_run' => $i % 2 === 0],
                'status' => 'succeeded',
                'requested_by_user_id' => $admin->id,
                'started_at' => now(),
                'finished_at' => now(),
                'summary' => ['dry_run' => true, 'tables' => [], 'changed_tables' => 0],
            ]);
            AdminCommandRun::query()->whereKey($run->id)->toBase()->update(['created_at' => now()->subMinutes(100 - $i)]);
        }
        // 別のコマンドの記録は含めない。
        AdminCommandRun::query()->create(['command_name' => 'paid-leave:assess-schedules', 'parameters' => [], 'status' => 'succeeded', 'requested_by_user_id' => $admin->id]);

        $response = $this->actingAs($admin)->getJson('/api/admin/read-model-rebuilds');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(20, $data);
        $this->assertNull($data[0]['summary']);
        $this->assertSame(['id', 'dry_run', 'status', 'requested_by', 'requested_at', 'started_at', 'finished_at', 'error_message', 'summary'], array_keys($data[0]));
        $times = array_column($data, 'requested_at');
        $sorted = $times;
        rsort($sorted);
        $this->assertSame($sorted, $times);
        $this->assertFalse($data[0]['dry_run']);
        $this->assertSame($admin->id, $data[0]['requested_by']['id']);
    }

    public function test_show_returns_one_run_with_the_summary(): void
    {
        $admin = $this->admin();
        $summary = ['dry_run' => true, 'tables' => [['table' => 'groups', 'before' => 2, 'after' => 2, 'changed' => false]], 'changed_tables' => 0];
        $run = AdminCommandRun::query()->create([
            'command_name' => ReadModelRebuildRunService::COMMAND_NAME,
            'parameters' => ['dry_run' => true],
            'status' => 'succeeded',
            'requested_by_user_id' => $admin->id,
            'started_at' => now(),
            'finished_at' => now(),
            'summary' => $summary,
        ]);

        $this->actingAs($admin)->getJson('/api/admin/read-model-rebuilds/'.$run->id)
            ->assertOk()
            ->assertJsonPath('data.id', $run->id)
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.summary', $summary);
    }

    public function test_show_returns_404_for_an_unknown_id_or_another_commands_run(): void
    {
        $admin = $this->admin();
        $other = AdminCommandRun::query()->create(['command_name' => 'paid-leave:assess-schedules', 'parameters' => [], 'status' => 'succeeded', 'requested_by_user_id' => $admin->id]);

        $this->actingAs($admin)->getJson('/api/admin/read-model-rebuilds/'.fake()->uuid())->assertNotFound();
        $this->actingAs($admin)->getJson('/api/admin/read-model-rebuilds/'.$other->id)->assertNotFound();
    }
}
