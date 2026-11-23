<?php

namespace Tests\Feature\EventSourcing;

use App\Domain\AccessControl\Commands\CreateRoleAssignment;
use App\Domain\AccessControl\Commands\UpdateRoleAssignment;
use App\Domain\AssetNumbering\Commands\ConfigureAssetNumberRule;
use App\Domain\Attendance\Commands\CreateAttendanceDay;
use App\Domain\Attendance\Commands\CreateCompanyCalendar;
use App\Domain\Attendance\Commands\CreateDefaultWorkStyle;
use App\Domain\Attendance\Commands\RecordAttendancePunch;
use App\Domain\Attendance\Commands\UpdateCompanyCalendar;
use App\Domain\Device\Commands\RegisterDevice;
use App\Domain\Device\Commands\UpdateDeviceSettings;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Rebuild\BooleanPropertyConverter;
use App\Domain\EventSourcing\Rebuild\LegacyBooleanPropertyDetector;
use App\Domain\EventSourcing\Rebuild\ReadModelFingerprinter;
use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use App\Domain\UserManagement\Commands\CreateGroupType;
use App\Domain\UserManagement\Commands\SetPaidLeaveAutoGrantEnabled;
use App\Domain\UserManagement\Commands\UpdateGroupType;
use App\Models\AdminCommandRun;
use App\Models\Role;
use App\Models\User;
use App\Services\EventSourcing\ReadModelRebuildRunService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\EventSourcing\Concerns\BuildsReadModelRebuildFixtures;
use Tests\TestCase;

/**
 * 旧項目名の真偽値のイベントの書き換え(運用コマンド events:rename-boolean-properties)のシナリオテスト
 * (docs/changesets/20261123-boolean-column-naming/spec.md 論点4・5・7)。
 *
 * 履歴は、Command/API(新しい項目名)で作ったあと、値を既定値と逆にそろえて ReadModel を作り直し(書き換え前のReadModelの代わり)、
 * 対象のイベントの行をこのテストに手書きした変換表の逆(旧項目名)に書き戻して作る。旧イベントは今のコードでは記録できないため。
 * 書き換え後は、全 ReadModel を空にして replay した結果が書き換え前と同じ(列名は migration で先に改名済み)になることを確かめる。
 */
class BooleanPropertyRenameScenarioTest extends TestCase
{
    use BuildsReadModelRebuildFixtures;
    use DatabaseMigrations;

    private const COMMAND = 'events:rename-boolean-properties';

    /**
     * 新しい項目名 => 旧項目名(このテストが独立に持つ逆引き。`BooleanPropertyConverter::RULES` の写しではない)。
     *
     * @var array<string, array{top?: array<string, string>, arrays?: array<string, array<string, string>>}>
     */
    private const OLD_NAMES = [
        'device.registered' => ['top' => ['allowsOffline' => 'allowOffline', 'requiresLocation' => 'requireLocation', 'punchTypeAutoDetectionEnabled' => 'autoDetectPunchType']],
        'device.settings_updated' => ['top' => ['allowsOffline' => 'allowOffline', 'requiresLocation' => 'requireLocation', 'punchTypeAutoDetectionEnabled' => 'autoDetectPunchType']],
        'attendance_punch.recorded' => ['top' => ['isOffline' => 'offline']],
        'company_calendar.created' => ['top' => ['allowsDailyHolidayOverride' => 'allowDailyHolidayOverride']],
        'company_calendar.updated' => ['top' => ['allowsDailyHolidayOverride' => 'allowDailyHolidayOverride']],
        'group_type.created' => ['top' => ['requiresPrimaryMembership' => 'primaryMembershipRequired']],
        'group_type.updated' => ['top' => ['requiresPrimaryMembership' => 'primaryMembershipRequired']],
        'role_assignment.created' => ['top' => ['includesDescendants' => 'includeDescendants']],
        'role_assignment.updated' => ['top' => ['includesDescendants' => 'includeDescendants']],
        'asset_number_rule.configured' => ['top' => ['isActive' => 'enabled']],
        'work_style.created' => ['arrays' => ['attributes' => ['is_system_generated' => 'system_generated']]],
        'attendance_day.calculated' => ['arrays' => ['calculation' => ['has_core_time_violation' => 'core_time_violation']]],
        'system_settings.updated' => ['arrays' => [
            'before' => ['prohibits_self_privileged_role_assignment' => 'prohibit_self_privileged_role_assignment'],
            'after' => ['prohibits_self_privileged_role_assignment' => 'prohibit_self_privileged_role_assignment'],
        ]],
        'external_integration_connection.created' => ['arrays' => ['after' => ['publishing_enabled' => 'enabled']]],
        'external_integration_connection.updated' => ['arrays' => [
            'before' => ['publishing_enabled' => 'enabled'],
            'after' => ['publishing_enabled' => 'enabled'],
        ]],
        'external_integration_connection.deleted' => ['arrays' => ['before' => ['publishing_enabled' => 'enabled']]],
    ];

    private User $admin;

    private User $worker;

    /** @var array<int, array<string, mixed>> 書き換え前の(新項目名の)行ごとの payload。id => payload */
    private array $expectedPayloads = [];

    /** @var list<int> 旧キーが無い古いイベントを再現するため、新キーを丸ごと取り除いた行の id */
    private array $legacyMissingIds = [];

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

    public function test_the_conversion_table_matches_the_events_in_the_changeset_table(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(self::OLD_NAMES), BooleanPropertyConverter::eventClasses());
        foreach (BooleanPropertyConverter::RULES as $class => $rule) {
            $expected = self::OLD_NAMES[$class];
            $this->assertSame(array_flip($expected['top'] ?? []), $rule['top'] ?? [], "{$class} のトップレベルの変換");
            $arrays = [];
            foreach ($rule['arrays'] ?? [] as $name => $arrayRule) {
                $arrays[$name] = array_flip($arrayRule['rename']);
            }
            $this->assertSame($expected['arrays'] ?? [], $arrays, "{$class} の配列の変換");
        }
        // 同名のキーを使う別のイベントは対象外。
        $this->assertNotContains('user.paid_leave_auto_grant_enabled_set', BooleanPropertyConverter::eventClasses());
        // 補う値の一覧。
        $defaults = [];
        foreach (BooleanPropertyConverter::RULES as $class => $rule) {
            foreach ($rule['defaults'] ?? [] as $key => $value) {
                $defaults["{$class}.{$key}"] = $value;
            }
            foreach ($rule['arrays'] ?? [] as $name => $arrayRule) {
                foreach ($arrayRule['defaults'] ?? [] as $key => $value) {
                    $defaults["{$class}.{$name}.{$key}"] = $value;
                }
            }
        }
        $this->assertSame([
            'attendance_punch.recorded.isOffline' => false,
            'company_calendar.created.allowsDailyHolidayOverride' => true,
            'company_calendar.updated.allowsDailyHolidayOverride' => true,
            'attendance_day.calculated.calculation.has_core_time_violation' => false,
        ], $defaults);
    }

    public function test_dry_run_shows_the_counts_and_examples_and_changes_nothing(): void
    {
        $this->buildLegacyHistory();
        $before = $this->snapshot();

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('試し実行')
            ->expectsOutputToContain('allowOffline=false → allowsOffline=false')
            ->expectsOutputToContain('offline=true → isOffline=true')
            ->expectsOutputToContain('core_time_violation=true → calculation.has_core_time_violation=true')
            ->assertExitCode(0);

        $this->assertEquals($before, $this->snapshot());
        $this->assertSame([], $this->backupTables(), '試し実行はバックアップ表を作らない');
        $run = AdminCommandRun::query()->where('command_name', self::COMMAND)->sole();
        $this->assertSame('succeeded', $run->status);
        $this->assertTrue($run->summary['dry_run']);
        $events = $run->summary['events'];
        $this->assertEqualsCanonicalizing(array_keys(self::OLD_NAMES), array_keys($events), '表の各イベントが対象になる');
        $this->assertSame($this->expectedCounts(), array_map(fn (array $e) => $e['target'], $events));
        $this->assertSame(1, $events['attendance_punch.recorded']['filled']);
        $this->assertSame(['(なし) → isOffline=false(補完)'], $events['attendance_punch.recorded']['filled_example']['changes']);
        $this->assertSame(['(なし) → allowsDailyHolidayOverride=true(補完)'], $events['company_calendar.created']['filled_example']['changes']);
        $this->assertSame(['(なし) → calculation.has_core_time_violation=false(補完)'], $events['attendance_day.calculated']['filled_example']['changes']);
        $this->assertSame(1, $events['company_calendar.created']['filled']);
        $this->assertSame(0, $events['company_calendar.updated']['filled']);
        $this->assertSame(1, $events['attendance_day.calculated']['filled']);
        $this->assertSame(0, $events['device.registered']['filled']);
        $this->assertSame(array_sum($this->expectedCounts()), $run->summary['target_count']);
        $this->assertGreaterThan(0, (new LegacyBooleanPropertyDetector)->count());
    }

    public function test_execute_rewrites_the_properties_only_and_rebuilds_the_read_models_to_the_same_state(): void
    {
        $this->buildLegacyHistory();
        $before = $this->snapshot();
        $beforeEvents = $this->storedEvents();
        $readModelsBefore = $this->readModelFingerprints();

        $this->artisan(self::COMMAND, ['--execute' => true])
            ->expectsOutputToContain('書き換えました')
            ->expectsOutputToContain('変更のあったテーブル0件(0件が合格)')
            ->assertExitCode(0);

        $this->assertSame(0, (new LegacyBooleanPropertyDetector)->count());
        $this->assertSame(0, DB::transactionLevel());

        // event_properties だけが変わる。id・集約ID・版・event_version・created_at・meta_data は変わらない。
        $afterEvents = $this->storedEvents();
        $this->assertSame(array_keys($beforeEvents), array_keys($afterEvents));
        foreach ($afterEvents as $id => $row) {
            $this->assertSame(
                array_diff_key($beforeEvents[$id], ['event_properties' => 1]),
                array_diff_key($row, ['event_properties' => 1]),
                "id={$id} の event_properties 以外",
            );
        }

        // 変換した行は、最初から新しい項目名で記録されていた状態(値は同じ)になる。変換しない行は完全に同じ。
        foreach ($afterEvents as $id => $row) {
            if (isset($this->expectedPayloads[$id])) {
                $this->assertEquals($this->expectedPayloads[$id], json_decode($row['event_properties'], true), "id={$id}");
            } else {
                $this->assertSame($beforeEvents[$id]['event_properties'], $row['event_properties'], "id={$id} は変えない");
            }
        }

        // 同名のキーを持つ対象外のイベントは変わらない。
        $autoGrant = DB::table('stored_events')->where('event_class', 'user.paid_leave_auto_grant_enabled_set')->get();
        $this->assertCount(1, $autoGrant);
        $this->assertFalse(json_decode($autoGrant[0]->event_properties, true)['enabled']);

        // 書き込み前と ReadModel の内容が一致し(列名は改名済み)、実行の記録に changed_tables=0 が残る。
        $this->assertSame($readModelsBefore, $this->readModelFingerprints());
        $run = AdminCommandRun::query()->where('command_name', self::COMMAND)->sole();
        $this->assertFalse($run->summary['dry_run']);
        $this->assertSame(0, $run->summary['changed_tables']);
        $backups = $this->backupTables();
        $this->assertCount(1, $backups);
        $this->assertSame($backups[0], $run->summary['backup_table']);
        $this->assertEquals($beforeEvents, $this->storedEvents($backups[0]), 'バックアップ表は変換前の行');
        $this->assertSame(0, DB::table('stored_event_corrections')->count(), '補正ログには書かない');
        $this->assertFalse(app(ReadModelRebuildRunService::class)->isRunning());

        // 全 ReadModel を空にして replay しても、同じ状態で、新しい列に期待値が入る。
        $this->artisan('read-models:rebuild')->assertExitCode(0);
        $this->assertSame($readModelsBefore, $this->readModelFingerprints());
        $this->assertReadModelValues();
        $this->assertNotEquals($before, $this->snapshot());
    }

    public function test_the_second_run_converts_nothing_and_mixed_old_and_new_rows_are_idempotent(): void
    {
        $this->buildLegacyHistory();
        // 配備後に新しい項目名で記録された行を想定: 旧名の行の一部を新名のまま(元の payload)に戻す。
        $keep = array_key_first(array_filter($this->expectedPayloads, fn ($p, $id) => DB::table('stored_events')->where('id', $id)->value('event_class') === 'device.registered', ARRAY_FILTER_USE_BOTH));
        DB::table('stored_events')->where('id', $keep)->update(['event_properties' => json_encode($this->expectedPayloads[$keep])]);

        $this->artisan(self::COMMAND, ['--execute' => true])->assertExitCode(0);
        $converted = AdminCommandRun::query()->where('command_name', self::COMMAND)->sole()->summary['target_count'];
        $this->assertSame(array_sum($this->expectedCounts()) - 1, $converted, '新キーの行は対象にならない');

        $after = $this->snapshot();
        $this->artisan(self::COMMAND, ['--execute' => true])->expectsOutputToContain('対象なし')->assertExitCode(0);
        $this->assertEquals($after, $this->snapshot());
        $this->assertCount(1, $this->backupTables(), '2回目はバックアップ表を作らない');
    }

    public function test_rows_with_both_old_and_new_keys_stop_the_command_without_changes(): void
    {
        $this->buildLegacyHistory();
        $id = (int) DB::table('stored_events')->where('event_class', 'attendance_punch.recorded')->orderBy('id')->value('id');
        $payload = json_decode(DB::table('stored_events')->where('id', $id)->value('event_properties'), true);
        $payload['isOffline'] = true;
        $payload['offline'] = true;
        DB::table('stored_events')->where('id', $id)->update(['event_properties' => json_encode($payload)]);
        $before = $this->snapshot();

        foreach ([[], ['--execute' => true]] as $options) {
            $this->artisan(self::COMMAND, $options)->expectsOutputToContain('旧キーと新キーが両方ある行')->assertExitCode(1);
        }

        $this->assertEquals($before, $this->snapshot());
        $this->assertSame([], $this->backupTables());
        $this->assertSame(2, AdminCommandRun::query()->where('command_name', self::COMMAND)->where('status', 'failed')->count());
    }

    public function test_a_row_missing_a_required_key_without_a_default_is_listed_refuses_execute_and_blocks_rebuilds(): void
    {
        $this->buildLegacyHistory();
        $id = (int) DB::table('stored_events')->where('event_class', 'device.registered')->orderBy('id')->value('id');
        $payload = json_decode(DB::table('stored_events')->where('id', $id)->value('event_properties'), true);
        unset($payload['allowOffline'], $payload['allowsOffline']);
        DB::table('stored_events')->where('id', $id)->update(['event_properties' => json_encode($payload)]);
        $before = $this->snapshot();

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain("id={$id} device.registered: allowOffline も allowsOffline も無く、補う値が無い")
            ->assertExitCode(0);
        $run = AdminCommandRun::query()->where('command_name', self::COMMAND)->where('status', 'succeeded')->sole();
        $this->assertSame([['id' => $id, 'event_class' => 'device.registered', 'reason' => 'allowOffline も allowsOffline も無く、補う値が無い']], $run->summary['unfillable']);

        $this->artisan(self::COMMAND, ['--execute' => true])->expectsOutputToContain('補えない行が1件あります')->assertExitCode(1);
        $this->assertEquals($before, $this->snapshot());
        $this->assertSame([], $this->backupTables());

        $this->assertGreaterThan(0, (new LegacyBooleanPropertyDetector)->count());
        $this->artisan('read-models:rebuild')->expectsOutputToContain('旧項目名の真偽値のイベントが')->assertExitCode(1);
        $this->artisan('projections:rebuild')->expectsOutputToContain('旧項目名の真偽値のイベントが')->assertExitCode(1);
    }

    public function test_snapshots_make_the_command_refuse(): void
    {
        $this->buildLegacyHistory();
        DB::table('snapshots')->insert([
            'aggregate_uuid' => (string) Str::uuid(), 'aggregate_version' => 1, 'state' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $before = $this->storedEvents();

        $this->artisan(self::COMMAND, ['--execute' => true])->expectsOutputToContain('snapshots')->assertExitCode(1);

        $this->assertEquals($before, $this->storedEvents());
        $this->assertSame([], $this->backupTables());
    }

    public function test_read_models_rebuild_and_projections_rebuild_are_refused_while_old_keys_remain(): void
    {
        $this->buildLegacyHistory();
        $stray = User::factory()->create();
        $events = DB::table('stored_events')->count();
        $runs = AdminCommandRun::query()->where('command_name', 'read-models:rebuild')->count();

        $this->artisan('read-models:rebuild')
            ->expectsOutputToContain('(events:rename-boolean-properties)を実行してください')
            ->assertExitCode(1);
        $this->artisan('read-models:rebuild', ['--dry-run' => true])->expectsOutputToContain('旧項目名の真偽値のイベントが')->assertExitCode(1);
        $this->artisan('projections:rebuild')->expectsOutputToContain('旧項目名の真偽値のイベントが')->assertExitCode(1);

        $this->assertTrue(DB::table('users')->where('id', $stray->id)->exists(), 'ReadModel は作り直されない');
        $this->assertSame($events, DB::table('stored_events')->count());
        $this->assertSame($runs, AdminCommandRun::query()->where('command_name', 'read-models:rebuild')->count(), '拒否したときは実行の記録を作らない');

        // イベントを経ない行があると書き換え後の作り直しの比較で止まるため、消してから実行する。
        DB::table('users')->where('id', $stray->id)->delete();
        $this->artisan(self::COMMAND, ['--execute' => true])->assertExitCode(0);
        $this->artisan('read-models:rebuild', ['--dry-run' => true])->assertExitCode(0);
        $this->artisan('read-models:rebuild')->assertExitCode(0);
    }

    public function test_a_read_model_difference_rolls_everything_back_and_keeps_the_backup(): void
    {
        $this->buildLegacyHistory();
        $before = $this->snapshot();
        $this->partialMock(ReadModelRebuilder::class, function ($mock): void {
            $mock->shouldReceive('rebuild')->once()->andReturn([
                'dry_run' => false, 'tables' => [['table' => 'devices', 'before' => 1, 'after' => 1, 'changed' => true]], 'changed_tables' => 1,
            ]);
        });

        $this->artisan(self::COMMAND, ['--execute' => true])
            ->expectsOutputToContain('書き換え前と一致しません(変更のあったテーブル: devices)')
            ->assertExitCode(1);

        $this->assertEquals($before, $this->snapshot(), 'stored_events は元の旧項目名のまま');
        $this->assertCount(1, $this->backupTables());
        $run = AdminCommandRun::query()->where('command_name', self::COMMAND)->sole();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('ロールバックしたため', $run->error_message);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(app(ReadModelRebuildRunService::class)->isRunning());
    }

    public function test_an_exception_during_the_rebuild_rolls_everything_back(): void
    {
        $this->buildLegacyHistory();
        $before = $this->snapshot();
        $this->partialMock(ReadModelRebuilder::class, function ($mock): void {
            $mock->shouldReceive('rebuild')->once()->andThrow(new RuntimeException('boom'));
        });

        $this->artisan(self::COMMAND, ['--execute' => true])->expectsOutputToContain('boom')->assertExitCode(1);

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_no_ddl_is_issued_inside_the_transaction_and_the_run_is_in_maintenance(): void
    {
        $this->buildLegacyHistory();
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = [$query->sql, DB::transactionLevel()];
        });
        $during = [];
        $real = app(ReadModelRebuilder::class);
        $this->partialMock(ReadModelRebuilder::class, function ($mock) use (&$during, $real): void {
            $mock->shouldReceive('rebuild')->once()->andReturnUsing(function (bool $dryRun, ?float $startedAt = null) use (&$during, $real): array {
                $during[] = app(ReadModelRebuildRunService::class)->isRunning();

                return $real->rebuild($dryRun, $startedAt);
            });
        });

        $this->artisan(self::COMMAND, ['--execute' => true])->assertExitCode(0);

        $ddl = fn (array $s) => preg_match('/^\s*(CREATE|ALTER|DROP|RENAME|TRUNCATE)\b/i', $s[0]) === 1;
        $this->assertSame([], array_values(array_filter($statements, fn (array $s) => $ddl($s) && $s[1] > 0)));
        $this->assertNotEmpty(array_filter($statements, fn (array $s) => $ddl($s) && $s[1] === 0), 'バックアップ表の作成はトランザクションの外');
        $this->assertSame([true], $during);
        $this->assertFalse(app(ReadModelRebuildRunService::class)->isRunning());
    }

    public function test_a_queued_run_through_the_runner_saves_the_output_and_summary(): void
    {
        $this->buildLegacyHistory();
        $admin = $this->createEventAdmin('Runner Admin');
        $run = AdminCommandRun::query()->create([
            'command_name' => self::COMMAND, 'parameters' => ['execute' => false], 'status' => 'queued', 'requested_by_user_id' => $admin->id,
        ]);
        $before = $this->snapshot();

        $this->assertSame(0, Artisan::call('admin-commands:run-pending'));

        $fresh = $run->fresh();
        $this->assertSame('succeeded', $fresh->status);
        $this->assertTrue($fresh->summary['dry_run']);
        $this->assertStringContainsString('試し実行', $fresh->output);
        $this->assertStringContainsString('allowOffline=false → allowsOffline=false', $fresh->output);
        $this->assertSame([], $this->backupTables());
        $this->assertArrayHasKey('stored_events', $before);
    }

    // ---- 履歴の組み立て ----

    protected function buildLegacyHistory(): void
    {
        $bus = app(CommandBus::class);
        $this->admin = $this->createEventAdmin('Boolean Admin');
        $this->worker = $this->createEventUser('Boolean Worker');
        $actor = $this->admin->id;

        // 端末: 既定値(allow=true, require=false, auto=false)と逆の値を使う。2台目は別の組み合わせ(キーの取り違えを検出する)。
        $deviceA = $bus->dispatch(new RegisterDevice('organization_shared', null, 'Reader A', 'android', ['punch_reader'], null, null, null, null, null, false, true, true, $actor))['device'];
        $bus->dispatch(new UpdateDeviceSettings($deviceA->id, 'Reader A2', null, null, null, null, null, false, true, true, $actor));
        $bus->dispatch(new RegisterDevice('organization_shared', null, 'Reader B', 'android', ['punch_reader'], null, null, null, null, null, true, true, false, $actor));

        // 会社カレンダー: 1つ目は false(既定 true と逆)、2つ目は未指定(旧イベントでは allowDailyHolidayOverride が無く、Projector が true にしていた)。
        $bus->dispatch(new CreateCompanyCalendar('Calendar False', 1, 4, 1, $actor, null, null, false));
        $calendarA = DB::table('company_calendars')->where('name', 'Calendar False')->value('id');
        $bus->dispatch(new UpdateCompanyCalendar($calendarA, 'Calendar False 2', 1, 4, 1, null, $actor, null, false));
        $bus->dispatch(new CreateCompanyCalendar('Calendar Default', 1, 4, 1, $actor));

        // グループ種別・ロール割当。
        $bus->dispatch(new CreateGroupType('TYPE_PRIMARY', 'Primary', 10, 'limited', 1, true, 1, $actor));
        $bus->dispatch(new CreateGroupType('TYPE_PLAIN', 'Plain', 11, 'unlimited', null, false, null, $actor));
        $typeId = (int) DB::table('group_types')->where('code', 'TYPE_PRIMARY')->value('id');
        $bus->dispatch(new UpdateGroupType($typeId, 'Primary 2', 10, 'active', 'limited', 1, true, 1, $actor));
        $roleId = (int) Role::query()->where('code', Role::EMPLOYEE)->value('id');
        $groupId = (string) DB::table('groups')->where('code', 'ALL_USERS')->value('id');
        $assignmentId = (string) Str::uuid();
        $bus->dispatch(new CreateRoleAssignment($assignmentId, 'user', $this->worker->id, $roleId, 'group', $groupId, true, null, null, $actor));
        $bus->dispatch(new UpdateRoleAssignment($assignmentId, 'group', $groupId, true, null, null, $actor));

        // 資産番号ルール(isActive=false)。
        $bus->dispatch(new ConfigureAssetNumberRule('laptop', 'PC', 4, false, $actor));

        // 標準の働き方(WorkStyleCreated の attributes.is_system_generated=true)。
        $bus->dispatch(new CreateDefaultWorkStyle([], $actor));

        // 打刻(isOffline=true と false)、手入力の日次勤怠(計算イベントが出る)。
        $bus->dispatch(new RecordAttendancePunch($this->worker->id, '2026-09-01', 'clock_in', '2026-09-01T09:00:00+09:00', 'device', null, $deviceA->id, null, $actor, true));
        $bus->dispatch(new RecordAttendancePunch($this->worker->id, '2026-09-02', 'clock_in', '2026-09-02T09:00:00+09:00', 'device', null, $deviceA->id, null, $actor, false));
        $bus->dispatch(new CreateAttendanceDay($this->worker->id, '2026-09-03', '2026-09-03T09:00:00+09:00', '2026-09-03T18:00:00+09:00', [], null, null, [], 'test', $actor));
        $bus->dispatch(new CreateAttendanceDay($this->worker->id, '2026-09-04', '2026-09-04T09:00:00+09:00', '2026-09-04T18:00:00+09:00', [], null, null, [], 'test', $actor));

        // 同名のキー(enabled)を持つ対象外のイベント。
        $bus->dispatch(new SetPaidLeaveAutoGrantEnabled($this->worker->id, false, $actor));

        // システム設定・外部連携(監査イベントのみ。正データは直接更新)。
        $this->actingAs($this->admin);
        $this->putJson('/api/admin/system-settings', ['default_timezone' => 'Asia/Tokyo', 'prohibits_self_privileged_role_assignment' => true])->assertOk();
        $connection = $this->postJson('/api/admin/external-integration-connections', [
            'provider' => 'freee', 'name' => 'freee', 'auth_type' => 'api_key', 'api_key' => 'secret', 'publishing_enabled' => true,
        ])->assertCreated()->json('id');
        $this->patchJson("/api/admin/external-integration-connections/{$connection}", ['publishing_enabled' => false])->assertOk();
        $this->deleteJson("/api/admin/external-integration-connections/{$connection}")->assertSuccessful();

        // 履歴の値を既定値と逆にそろえる(自然には作れない値)。ReadModel は、そろえた履歴から作り直す。
        $this->forceValues();
        $this->artisan('read-models:rebuild')->assertExitCode(0);
        $this->assertSame(0, (new LegacyBooleanPropertyDetector)->count());

        $this->toLegacy();
    }

    /** 計算イベントの has_core_time_violation と WorkStyleCreated の is_system_generated を、既定値と逆(true)にする。 */
    private function forceValues(): void
    {
        $violation = true;
        foreach (DB::table('stored_events')->where('event_class', 'attendance_day.calculated')->orderBy('id')->get() as $row) {
            $payload = json_decode($row->event_properties, true);
            if ($violation && $payload['calculation']['has_core_time_violation'] === false) {
                $payload['calculation']['has_core_time_violation'] = true;
                DB::table('stored_events')->where('id', $row->id)->update(['event_properties' => json_encode($payload)]);
                $violation = false;
            }
        }
        $this->assertFalse($violation, '計算イベントが少なくとも1件ある');
    }

    /** 対象のイベントの行を旧項目名に書き戻す(全て、先に保存した新項目名の payload と比べるため id => payload を残す)。 */
    private function toLegacy(): void
    {
        $this->expectedPayloads = [];
        $this->legacyMissingIds = [];
        // 旧キーが無い古いイベントを再現する行(既定値と同じ値の最初の1件だけ、新キーを取り除く。取り除いても Projector の既定値と同じ状態になる)。
        $dropCandidates = [
            'attendance_punch.recorded' => fn (array $p) => $p['isOffline'] === false,
            'company_calendar.created' => fn (array $p) => $p['name'] === 'Calendar Default' && $p['allowsDailyHolidayOverride'] === true,
            'attendance_day.calculated' => fn (array $p) => $p['calculation']['has_core_time_violation'] === false,
        ];
        $dropped = [];

        foreach (DB::table('stored_events')->whereIn('event_class', array_keys(self::OLD_NAMES))->orderBy('id')->get() as $row) {
            $class = $row->event_class;
            $new = json_decode($row->event_properties, true);
            $payload = $new;
            $drop = isset($dropCandidates[$class]) && ! isset($dropped[$class]) && $dropCandidates[$class]($new);

            foreach (self::OLD_NAMES[$class]['top'] ?? [] as $newName => $oldName) {
                if (array_key_exists($newName, $payload)) {
                    $payload = $this->renameKey($payload, $newName, $oldName);
                    if ($drop) {
                        unset($payload[$oldName]);
                    }
                }
            }
            foreach (self::OLD_NAMES[$class]['arrays'] ?? [] as $array => $map) {
                if (isset($payload[$array]) && is_array($payload[$array])) {
                    foreach ($map as $newName => $oldName) {
                        if (array_key_exists($newName, $payload[$array])) {
                            $payload[$array] = $this->renameKey($payload[$array], $newName, $oldName);
                            if ($drop) {
                                unset($payload[$array][$oldName]);
                            }
                        }
                    }
                }
            }
            if ($drop) {
                $dropped[$class] = true;
                $this->legacyMissingIds[] = (int) $row->id;
            }

            $this->expectedPayloads[(int) $row->id] = $new;
            DB::table('stored_events')->where('id', $row->id)->update(['event_properties' => json_encode($payload)]);
        }
        $this->assertCount(3, $this->legacyMissingIds, '旧キーが無い行を3種類とも再現できた');
    }

    /**
     * キーの位置を保って名前を変える。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function renameKey(array $data, string $from, string $to): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[$key === $from ? $to : $key] = $value;
        }

        return $result;
    }

    /** @return array<string, int> 変換の対象になる行数(旧項目名に書き戻した行数) */
    private function expectedCounts(): array
    {
        $counts = array_count_values(array_map(
            fn (int $id) => (string) DB::table('stored_events')->where('id', $id)->value('event_class'),
            array_keys($this->expectedPayloads),
        ));
        ksort($counts);

        return $counts;
    }

    /** @param  list<string>  $values */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    // ---- 確認 ----

    private function assertReadModelValues(): void
    {
        $a = DB::table('devices')->where('name', 'Reader A2')->first();
        $this->assertSame([0, 1, 1], [(int) $a->allows_offline, (int) $a->requires_location, (int) $a->punch_type_auto_detection_enabled]);
        $b = DB::table('devices')->where('name', 'Reader B')->first();
        $this->assertSame([1, 1, 0], [(int) $b->allows_offline, (int) $b->requires_location, (int) $b->punch_type_auto_detection_enabled]);

        $offline = DB::table('attendance_punches')->orderBy('punched_at')->pluck('is_offline')->map(fn ($v) => (int) $v)->all();
        $this->assertSame([1, 0], $offline);

        $this->assertSame(0, (int) DB::table('company_calendars')->where('name', 'Calendar False 2')->value('allows_daily_holiday_override'));
        $this->assertSame(1, (int) DB::table('company_calendars')->where('name', 'Calendar Default')->value('allows_daily_holiday_override'));

        $this->assertSame(1, (int) DB::table('group_types')->where('code', 'TYPE_PRIMARY')->value('requires_primary_membership'));
        $this->assertSame(0, (int) DB::table('group_types')->where('code', 'TYPE_PLAIN')->value('requires_primary_membership'));
        $this->assertSame(1, (int) DB::table('role_assignments')->where('subject_id', $this->worker->id)->value('includes_descendants'));

        $this->assertSame(1, (int) DB::table('work_styles')->where('is_default', true)->value('is_system_generated'));
        $this->assertGreaterThan(0, DB::table('attendance_daily_calculations')->where('has_core_time_violation', true)->count());
        $this->assertGreaterThan(0, DB::table('attendance_daily_calculations')->where('has_core_time_violation', false)->count());
        $this->assertSame(0, (int) DB::table('asset_number_rules')->where('category', 'laptop')->value('is_active'));
    }

    /** @return array<string, array{count: int, hash: string}|null> */
    private function readModelFingerprints(): array
    {
        $fingerprinter = new ReadModelFingerprinter;
        $result = [];
        foreach ((new ReadModelRebuilder)->existingTables() as $table) {
            $result[$table] = $fingerprinter->fingerprint($table);
        }

        return $result;
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function snapshot(): array
    {
        return ['stored_events' => $this->storedEvents(), 'devices' => $this->rows('devices'), 'users' => $this->rows('users')];
    }

    /** @return array<int, array<string, mixed>> id => 行 */
    private function storedEvents(string $table = 'stored_events'): array
    {
        $rows = [];
        foreach (DB::table($table)->orderBy('id')->get() as $row) {
            $rows[(int) $row->id] = (array) $row;
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(string $table): array
    {
        return DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
    }

    /** @return list<string> */
    private function backupTables(): array
    {
        return array_values(array_filter(
            array_map(fn (array $table) => $table['name'], Schema::getTables()),
            fn (string $name) => str_starts_with($name, 'stored_events_backup_'),
        ));
    }
}
