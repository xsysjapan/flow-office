<?php

namespace Tests\Feature\EventSourcing;

use App\Domain\AssetNumbering\Commands\ConfigureAssetNumberRule;
use App\Domain\EventSourcing\CommandBus;
use App\Models\AdminCommandRun;
use App\Models\User;
use App\Models\UserCredential;
use App\Services\EventSourcing\CorrectionPendingService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\EventSourcing\Concerns\BuildsReadModelRebuildFixtures;
use Tests\TestCase;

/**
 * 補正待ちの間のメンテナンス(旧形式のイベントが残っている間、API を 503 にする)のシナリオテスト
 * (docs/changesets/20261124-correction-pending-maintenance/spec.md 論点1・3)。
 *
 * 旧形式の状態は、検出器ごとに stored_events の payload を旧い項目名に書き換えて(または旧形式の行を足して)作る。
 * 補正の前は、ログイン・auth/me・運用コマンドの一覧と依頼・実行ができ、改名した対象の API(端末の打刻・ロールの割当の変更)と
 * 定期処理(`admin-commands:run-pending` 以外)が止まること、補正の後は通常どおりに動くことを確かめる。
 */
class CorrectionPendingMaintenanceTest extends TestCase
{
    use BuildsReadModelRebuildFixtures;
    use DatabaseMigrations;

    private const PASSWORD = 'correct-horse-battery-staple';

    private const BOOLEAN = 'boolean';

    private const USER_SETTINGS = 'user_settings';

    private const BULK = 'calendar_bulk';

    private User $admin;

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

        $this->admin = $this->createEventAdmin('Pending Admin');
        UserCredential::query()->create(['user_id' => $this->admin->id, 'password' => self::PASSWORD]);
    }

    protected function tearDown(): void
    {
        $this->clearRebuildHeartbeat();
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function legacyKinds(): array
    {
        return [
            '真偽値の項目名' => [self::BOOLEAN],
            '利用者の設定値' => [self::USER_SETTINGS],
            '一括操作の対象行id' => [self::BULK],
        ];
    }

    /** 検出器が陽性になる旧形式のイベントを作る。 */
    private function makeLegacy(string $kind): void
    {
        switch ($kind) {
            case self::BOOLEAN:
                // Command(新しい項目名)で記録した行を、旧い項目名に書き戻す。
                app(CommandBus::class)->dispatch(new ConfigureAssetNumberRule('laptop', 'PC', 4, false, $this->admin->id));
                $row = DB::table('stored_events')->where('event_class', 'asset_number_rule.configured')->first();
                $payload = json_decode($row->event_properties, true);
                $payload['enabled'] = $payload['isActive'];
                unset($payload['isActive']);
                DB::table('stored_events')->where('id', $row->id)->update(['event_properties' => json_encode($payload)]);
                break;
            case self::USER_SETTINGS:
                // 別の利用者の作成イベントから、タイムゾーンのキーを取り除く(ログインする管理者は触らない)。
                $other = $this->createEventUser('Legacy Worker');
                $row = DB::table('stored_events')->where('event_class', 'user.created_manually')->where('aggregate_uuid', $other->id)->first();
                $payload = json_decode($row->event_properties, true);
                unset($payload['attributes']['timezone']);
                DB::table('stored_events')->where('id', $row->id)->update(['event_properties' => json_encode($payload)]);
                break;
            case self::BULK:
                // 旧い体系の id を持つ取消イベント(適用イベントが無いため、どの id も旧い体系とみなされる)。
                DB::table('stored_events')->insert([
                    'aggregate_uuid' => (string) Str::uuid(),
                    'aggregate_version' => 1,
                    'event_version' => 1,
                    'event_class' => 'calendar_bulk_operation.reverted',
                    'event_properties' => json_encode(['revertedTargetIds' => ['legacy-target-1'], 'excludedTargetIds' => []]),
                    'meta_data' => json_encode([]),
                    'created_at' => now(),
                ]);
                break;
        }
        // 検出器の結果のキャッシュ(60秒)を、旧形式の状態を作った時点で消す。
        app(CorrectionPendingService::class)->forget();
    }

    /** @return array<string, string> */
    private function login(): array
    {
        $response = $this->postJson('/api/auth/local-login', ['email' => $this->admin->email, 'password' => self::PASSWORD]);
        $response->assertOk();

        return ['Authorization' => 'Bearer '.$response->json('token')];
    }

    private function assertPendingBlocked(array $headers): void
    {
        $response = $this->withHeaders($headers)->postJson('/api/device-punches', []);
        $response->assertStatus(503)->assertJsonPath('code', 'correction_pending')->assertJsonPath('message', CorrectionPendingService::MESSAGE);

        $this->withHeaders($headers)->patchJson('/api/admin/access-control/role-assignments/'.Str::uuid(), ['includes_descendants' => true])
            ->assertStatus(503)->assertJsonPath('code', 'correction_pending');
        $this->withHeaders($headers)->getJson('/api/admin/access-control/role-assignments')->assertStatus(503)->assertJsonPath('code', 'correction_pending');
        $this->withHeaders($headers)->postJson('/api/attendance/clock-in')->assertStatus(503)->assertJsonPath('code', 'correction_pending');
    }

    private function assertNormal(array $headers): void
    {
        $this->withHeaders($headers)->getJson('/api/admin/access-control/role-assignments')->assertOk();
        // 端末のトークンではないため通常の拒否や検証エラーになるが、メンテナンスの503にはならない。
        $this->assertNotSame(503, $this->withHeaders($headers)->postJson('/api/device-punches', [])->status());
        $this->assertNotSame(503, $this->withHeaders($headers)->patchJson('/api/admin/access-control/role-assignments/'.Str::uuid(), [])->status());
    }

    /** 運用コマンドの実行の依頼を出し、実行役で実行して、その記録を返す。 */
    private function requestAndRun(array $headers, string $command, array $parameters): AdminCommandRun
    {
        $response = $this->withHeaders($headers)->postJson("/api/admin/commands/{$command}/runs", ['parameters' => $parameters]);
        $response->assertStatus(202);
        $this->assertSame(0, Artisan::call('admin-commands:run-pending'));

        return AdminCommandRun::query()->findOrFail($response->json('data.id'));
    }

    #[DataProvider('legacyKinds')]
    public function test_login_and_the_admin_command_screen_work_while_correction_is_pending_but_renamed_aggregates_are_blocked(string $kind): void
    {
        $this->makeLegacy($kind);
        $this->assertTrue(app(CorrectionPendingService::class)->isPending());

        // ログイン(POST)と認証。
        $headers = $this->login();
        $this->withHeaders($headers)->getJson('/api/auth/me')->assertOk()->assertJsonPath('id', $this->admin->id);
        $this->withHeaders($headers)->getJson('/api/access/me')->assertOk();
        $this->getJson('/api/maintenance-status')->assertOk()->assertExactJson(['maintenance' => false]);

        // 運用コマンドの一覧・実行記録(GET)と、実行の依頼(POST)。
        $this->withHeaders($headers)->getJson('/api/admin/commands')->assertOk();
        $this->withHeaders($headers)->getJson('/api/admin/command-runs')->assertOk();
        $this->withHeaders($headers)->postJson('/api/admin/commands/events:rename-boolean-properties/runs', ['parameters' => []])->assertStatus(202);

        // 改名した対象の API・通常の API・ログアウト以外の書き込みは 503。
        $this->assertPendingBlocked($headers);
        $this->assertSame(AdminCommandRun::query()->count(), 1);
    }

    public function test_the_rename_boolean_properties_dry_run_runs_through_the_admin_command_path_while_pending(): void
    {
        $this->makeLegacy(self::BOOLEAN);
        $headers = $this->login();
        $before = DB::table('stored_events')->orderBy('id')->pluck('event_properties', 'id')->all();

        $run = $this->requestAndRun($headers, 'events:rename-boolean-properties', []);

        $this->assertSame('succeeded', $run->status);
        $this->assertTrue($run->summary['dry_run']);
        $this->assertSame($before, DB::table('stored_events')->orderBy('id')->pluck('event_properties', 'id')->all(), '試し実行は何も書かない');
        // 試し実行は補正ではないので、補正待ちのまま。
        $this->assertTrue(app(CorrectionPendingService::class)->isPending());
        $this->assertPendingBlocked($headers);
    }

    public function test_after_the_boolean_correction_everything_is_normal(): void
    {
        $this->makeLegacy(self::BOOLEAN);
        $headers = $this->login();
        $this->assertPendingBlocked($headers);

        $run = $this->requestAndRun($headers, 'events:rename-boolean-properties', ['execute' => true]);

        $this->assertSame('succeeded', $run->status, (string) $run->output.$run->error_message);
        $this->assertFalse(app(CorrectionPendingService::class)->isPending());
        $this->assertNormal($headers);
        $this->getJson('/api/maintenance-status')->assertOk()->assertExactJson(['maintenance' => false]);
    }

    public function test_after_the_user_settings_correction_everything_is_normal(): void
    {
        $this->makeLegacy(self::USER_SETTINGS);
        $headers = $this->login();
        $this->assertPendingBlocked($headers);

        $run = $this->requestAndRun($headers, 'events:fix-user-settings-at-replay', ['execute' => true]);

        $this->assertSame('succeeded', $run->status, (string) $run->output.$run->error_message);
        $this->assertFalse(app(CorrectionPendingService::class)->isPending());
        $this->assertNormal($headers);
    }

    public function test_the_cache_is_cleared_when_any_correction_command_finishes_even_if_it_fails(): void
    {
        $this->makeLegacy(self::BULK);
        $headers = $this->login();
        $this->assertPendingBlocked($headers);

        // 旧形式の行を消した(別の経路で補正された)あとも、キャッシュが残る間は止まったまま。
        DB::table('stored_events')->where('event_class', 'calendar_bulk_operation.reverted')->delete();
        $this->assertTrue(Cache::has(CorrectionPendingService::CACHE_KEY));
        $this->assertTrue(app(CorrectionPendingService::class)->isPending());

        // 補正のコマンド(失敗しても)が終わるとキャッシュが消え、メンテナンスが解ける。
        $run = $this->requestAndRun($headers, 'events:fix-calendar-bulk-operation-target-ids', []);

        $this->assertFalse(Cache::has(CorrectionPendingService::CACHE_KEY));
        $this->assertFalse(app(CorrectionPendingService::class)->isPending());
        $this->assertContains($run->status, ['succeeded', 'failed']);
        $this->assertNormal($headers);
    }

    public function test_the_result_is_cached_and_does_not_count_on_every_request(): void
    {
        $this->makeLegacy(self::BOOLEAN);
        $this->assertTrue(app(CorrectionPendingService::class)->isPending());

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertTrue(app(CorrectionPendingService::class)->isPending());
        $this->assertSame([], DB::getQueryLog(), '2回目はキャッシュを使い、stored_events を読まない');
        DB::disableQueryLog();
    }

    public function test_nothing_is_blocked_without_legacy_events(): void
    {
        $this->assertFalse(app(CorrectionPendingService::class)->isPending());
        $headers = $this->login();
        $this->assertNormal($headers);
    }

    public function test_the_correction_pending_state_is_distinct_from_the_maintenance_code(): void
    {
        $this->makeLegacy(self::BOOLEAN);

        // 認証より前に判定する(未認証でも 503)。フロントの全画面のメンテナンス表示(code=maintenance)にはしない。
        $this->postJson('/api/onboarding/local', [])->assertStatus(503)->assertJsonPath('code', 'correction_pending');
        $this->getJson('/api/users')->assertStatus(503)->assertJsonPath('code', 'correction_pending');
    }

    public function test_scheduled_tasks_except_the_admin_command_runner_are_skipped_while_pending(): void
    {
        $this->app->make(Kernel::class)->bootstrap();
        $events = collect($this->app->make(Schedule::class)->events());
        $this->assertGreaterThan(5, $events->count());
        $this->assertTrue($events->every(fn ($event) => $event->filtersPass($this->app)), '通常は全て動く');

        $this->makeLegacy(self::BOOLEAN);

        $runner = 0;
        foreach ($events as $event) {
            if (str_contains($event->command, 'admin-commands:run-pending')) {
                $runner++;
                $this->assertTrue($event->filtersPass($this->app), '運用コマンドの実行役は動く');

                continue;
            }
            $this->assertFalse($event->filtersPass($this->app), $event->command);
        }
        $this->assertSame(1, $runner);
    }
}
