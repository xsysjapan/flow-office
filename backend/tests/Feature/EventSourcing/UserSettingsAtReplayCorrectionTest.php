<?php

namespace Tests\Feature\EventSourcing;

use App\Domain\Attendance\Commands\ApplyCalendarBulkOperation;
use App\Domain\Attendance\Commands\CreateDefaultWorkStyle;
use App\Domain\Attendance\Commands\RevertCalendarBulkOperation;
use App\Domain\Attendance\Projectors\CalendarBulkOperationProjector;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Rebuild\LegacyCalendarBulkOperationTargetIdDetector;
use App\Domain\EventSourcing\Rebuild\LegacyUserSettingsDetector;
use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use App\Domain\EventSourcing\Rebuild\UserSettingsAtReplayMigrator;
use App\Domain\SystemSettings\Aggregates\SystemSettingsAuditAggregate;
use App\Domain\UserManagement\Commands\ApplyExternalHrImport;
use App\Domain\UserManagement\Commands\ChangeFieldAuthority;
use App\Domain\UserManagement\Commands\RecordSsoLogin;
use App\Domain\UserManagement\Commands\SyncUsersFromMs365;
use App\Domain\UserManagement\Commands\UpdateUserProfile;
use App\Domain\UserManagement\Graph\MicrosoftGraphClient;
use App\Domain\UserManagement\Graph\MicrosoftGraphUser;
use App\Domain\UserManagement\Handlers\SyncUsersFromMs365Handler;
use App\Domain\UserManagement\Services\StandardGroupMembershipRecorder;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\EventSourcing\Concerns\BuildsReadModelRebuildFixtures;
use Tests\TestCase;

/**
 * 利用者の設定値の補正(events:fix-user-settings-at-replay)と、一括操作の補正とまとめた補正(events:fix-rebuild-determinism)の
 * シナリオテスト(docs/changesets/20261124-user-projection-settings-at-replay/spec.md 論点2・3)。
 *
 * 旧データ(タイムゾーン・テナントIDのキーが無いイベント、旧idの一括操作)は、新しいコードで作った履歴の stored_events の
 * payload からキーを消す・ランダムなidに置き換える直接のUPDATEで再現する(テストの前提データ作り)。
 */
class UserSettingsAtReplayCorrectionTest extends TestCase
{
    use BuildsReadModelRebuildFixtures;
    use DatabaseMigrations;

    private const USER_COMMAND = 'events:fix-user-settings-at-replay';

    private const BULK_COMMAND = 'events:fix-calendar-bulk-operation-target-ids';

    private const COMBINED_COMMAND = 'events:fix-rebuild-determinism';

    private const TARGETS = 'calendar_bulk_operation_targets';

    private const USER_EVENT_CLASSES = ['user.created_manually', 'user.onboarded_as_admin', 'user.created_from_sso_login', 'user.synced_from_ms365', 'user.sso_account_linked', 'external_hr.import_applied'];

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
        SystemSetting::current()->update(['default_timezone' => 'Europe/London', 'm365_tenant_id' => 'tenant-A']);
    }

    protected function tearDown(): void
    {
        $this->clearRebuildHeartbeat();
        parent::tearDown();
    }

    private function bus(): CommandBus
    {
        return app(CommandBus::class);
    }

    /** 旧コードの状態にする: タイムゾーン・テナントIDのキーを消す(HR取込は新規の行のtimezone)。 */
    private function stripKeys(): void
    {
        foreach (DB::table('stored_events')->whereIn('event_class', self::USER_EVENT_CLASSES)->get() as $event) {
            $payload = json_decode($event->event_properties, true, 512, JSON_THROW_ON_ERROR);
            unset($payload['timezone'], $payload['attributes']['timezone'], $payload['externalTenantId']);
            foreach ($payload['rows'] ?? [] as $i => $row) {
                unset($payload['rows'][$i]['timezone']);
            }
            DB::table('stored_events')->where('id', $event->id)->update(['event_properties' => json_encode($payload)]);
        }
    }

    /** @return array<string, mixed> */
    private function payloadOf(string $eventClass, int $index = 0): array
    {
        $row = DB::table('stored_events')->where('event_class', $eventClass)->orderBy('id')->skip($index)->first();

        return json_decode((string) $row->event_properties, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<int, array<string, mixed>> */
    private function storedEvents(): array
    {
        return DB::table('stored_events')->orderBy('id')->get()->keyBy('id')->map(fn ($row) => (array) $row)->all();
    }

    /** 時刻列(作り直しで変わる)を除いた表の行。 */
    private function tableRows(string $table): array
    {
        return DB::table($table)->orderBy($table === 'users' ? 'id' : 'external_subject_id')->get()->map(function ($row) use ($table) {
            $row = (array) $row;
            unset($row['created_at'], $row['updated_at']);
            if ($table === 'external_identities') {
                unset($row['id']); // 自動採番の主キーは作り直しで変わる
            }

            return $row;
        })->all();
    }

    /** @return list<string> */
    private function backupTables(): array
    {
        return array_values(array_filter(
            array_column(Schema::getTables(), 'name'),
            fn (string $name) => str_starts_with($name, UserSettingsAtReplayMigrator::BACKUP_PREFIX) || str_starts_with($name, 'stored_events_backup_calbulk_'),
        ));
    }

    private function correctionLogCount(string $key): int
    {
        return DB::table('stored_event_corrections')->where('correction_key', $key)->count();
    }

    /** 設定の更新をイベントとして記録し、ライブの設定も同じ値にする(設定の履歴の前提データ)。 */
    private function recordTimezoneSetting(string $before, string $after, string $actorId): void
    {
        SystemSettingsAuditAggregate::retrieve((string) Str::uuid())->recordUpdate(['default_timezone' => $before], ['default_timezone' => $after], $actorId)->persist();
        SystemSetting::current()->update(['default_timezone' => $after]);
    }

    /** 設定を変えてから作り直しの試し実行をし、全表が変わらないことを確かめる。 */
    private function assertRebuildUnchanged(): void
    {
        $summary = (new ReadModelRebuilder)->rebuild(true);
        $this->assertSame([], array_column(array_filter($summary['tables'], fn (array $row) => $row['changed']), 'table'));
    }

    /** @param  array<int, MicrosoftGraphUser>  $users */
    private function syncFromMs365(array $users): void
    {
        $client = new class($users) implements MicrosoftGraphClient
        {
            public function __construct(private readonly array $users) {}

            public function listUsers(): iterable
            {
                return $this->users;
            }
        };
        (new SyncUsersFromMs365Handler($client, app(StandardGroupMembershipRecorder::class)))->handle(new SyncUsersFromMs365);
    }

    // ---- 試し実行・実行・冪等 ----

    public function test_dry_run_is_the_default_and_changes_nothing(): void
    {
        $this->createEventUser('Manual');
        $this->bus()->dispatch(new RecordSsoLogin('entra-1', 'SSO', 'sso@example.com'));
        $this->stripKeys();
        $events = $this->storedEvents();
        $this->assertSame(2, (new LegacyUserSettingsDetector)->count());

        $this->artisan(self::USER_COMMAND)
            ->expectsOutputToContain('試し実行(データは変更していません')
            ->assertExitCode(0);

        $this->assertSame($events, $this->storedEvents());
        $this->assertSame([], $this->backupTables());
        $this->assertSame(0, $this->correctionLogCount(UserSettingsAtReplayMigrator::CORRECTION_KEY));
        $this->assertSame(1, DB::table('system_settings')->count(), '設定の行を増やさない');
    }

    public function test_execute_fills_the_values_and_the_rebuild_matches_the_live_data_even_after_settings_change(): void
    {
        $manual = $this->createEventUser('Manual');
        $this->bus()->dispatch(new RecordSsoLogin('entra-1', 'SSO', 'sso@example.com'));
        $users = $this->tableRows('users');
        $identities = $this->tableRows('external_identities');
        $this->stripKeys();
        $beforeEvents = $this->storedEvents();

        $this->artisan(self::USER_COMMAND, ['--execute' => true])->expectsOutputToContain('書き換えました')->assertExitCode(0);
        $this->assertSame(0, DB::transactionLevel());

        // 書き足したのは対象のキーだけ。イベントの数・他の列は変わらない。
        $afterEvents = $this->storedEvents();
        $this->assertSame(array_keys($beforeEvents), array_keys($afterEvents));
        foreach ($afterEvents as $id => $row) {
            $this->assertSame(array_diff_key($beforeEvents[$id], ['event_properties' => 1]), array_diff_key($row, ['event_properties' => 1]));
        }
        $this->assertSame('Europe/London', $this->payloadOf('user.created_manually')['attributes']['timezone']);
        $sso = $this->payloadOf('user.created_from_sso_login');
        $this->assertSame('Europe/London', $sso['timezone']);
        $this->assertSame('tenant-A', $sso['externalTenantId']);

        // users・external_identities は書き換え前のライブと同じ。設定を変えて作り直しても変わらない。
        $this->assertEquals($users, $this->tableRows('users'));
        $this->assertEquals($identities, $this->tableRows('external_identities'));
        SystemSetting::current()->update(['default_timezone' => 'America/New_York', 'm365_tenant_id' => 'tenant-B']);
        $this->assertRebuildUnchanged();
        $this->assertSame('Europe/London', User::query()->findOrFail($manual->id)->timezone);
        $this->assertSame(0, (new LegacyUserSettingsDetector)->count());
    }

    public function test_execute_writes_the_backup_table_and_the_correction_log(): void
    {
        $this->bus()->dispatch(new RecordSsoLogin('entra-1', 'SSO', 'sso@example.com'));
        $this->stripKeys();
        $beforeRow = (array) DB::table('stored_events')->where('event_class', 'user.created_from_sso_login')->first();

        $this->artisan(self::USER_COMMAND, ['--execute' => true])->assertExitCode(0);

        $backups = $this->backupTables();
        $this->assertCount(1, $backups);
        $backupRows = DB::table($backups[0])->get();
        $this->assertCount(1, $backupRows);
        $this->assertSame($beforeRow['id'], $backupRows[0]->id);
        $this->assertSame($beforeRow['event_properties'], $backupRows[0]->event_properties);

        $logs = DB::table('stored_event_corrections')->where('correction_key', 'user_settings_at_replay')->get();
        $this->assertCount(1, $logs);
        $this->assertSame((int) $beforeRow['id'], (int) $logs[0]->stored_event_id);
        $this->assertSame('rewrite', $logs[0]->operation);
        $this->assertSame($beforeRow['event_properties'], $logs[0]->before_event_properties);
        $this->assertSame($backups[0], $logs[0]->backup_table);
        $this->assertSame(DB::table('stored_events')->where('id', $beforeRow['id'])->value('event_properties'), $logs[0]->after_event_properties);
    }

    public function test_it_is_idempotent(): void
    {
        $this->createEventUser('Manual');
        $this->bus()->dispatch(new RecordSsoLogin('entra-1', 'SSO', 'sso@example.com'));
        $this->stripKeys();
        $this->artisan(self::USER_COMMAND, ['--execute' => true])->assertExitCode(0);
        $events = $this->storedEvents();
        $logs = $this->correctionLogCount('user_settings_at_replay');

        $this->artisan(self::USER_COMMAND)->expectsOutputToContain('対象なし')->assertExitCode(0);
        $this->artisan(self::USER_COMMAND, ['--execute' => true])->expectsOutputToContain('対象なし')->assertExitCode(0);

        $this->assertSame($events, $this->storedEvents());
        $this->assertCount(1, $this->backupTables());
        $this->assertSame($logs, $this->correctionLogCount('user_settings_at_replay'));
    }

    // ---- タイムゾーンの値の決め方 ----

    public function test_a_user_whose_timezone_was_changed_later_is_restored_from_the_settings_history_and_the_others_use_the_live_value(): void
    {
        $admin = $this->createEventUser('Admin');
        $a = $this->createEventUser('User A');
        // 作成の後に設定を変え(履歴: Asia/Tokyo → Europe/Paris)、その後に作った利用者Bと、Aのプロフィール更新(タイムゾーン)を記録する。
        $this->recordTimezoneSetting('Asia/Tokyo', 'Europe/Paris', $admin->id);
        $b = $this->createEventUser('User B');
        $this->bus()->dispatch(new UpdateUserProfile($a->id, ['timezone' => 'America/Chicago'], $admin->id));
        DB::table('users')->where('id', $b->id)->update(['timezone' => 'Asia/Seoul']);
        $this->stripKeys();

        $this->artisan(self::USER_COMMAND)
            ->expectsOutputToContain("利用者={$b->id} ライブ=Asia/Seoul 履歴=Europe/Paris")
            ->assertExitCode(0);
        $this->artisan(self::USER_COMMAND, ['--execute' => true])->assertExitCode(0);

        $timezones = [];
        foreach (DB::table('stored_events')->where('event_class', 'user.created_manually')->orderBy('id')->get() as $row) {
            $timezones[$row->aggregate_uuid] = json_decode($row->event_properties, true)['attributes']['timezone'];
        }
        // A: 後にタイムゾーンを書くイベントがあるので、作成の時点の設定(最初の更新の before)。B: ライブの値。管理者: ライブの値。
        $this->assertSame('Asia/Tokyo', $timezones[$a->id]);
        $this->assertSame('Asia/Seoul', $timezones[$b->id]);
        $this->assertSame('Europe/London', $timezones[$admin->id]);
        $this->assertSame('America/Chicago', User::query()->findOrFail($a->id)->timezone);
        $this->assertRebuildUnchanged();
    }

    public function test_the_history_uses_the_last_update_before_the_event(): void
    {
        $admin = $this->createEventUser('Admin');
        $this->recordTimezoneSetting('Asia/Tokyo', 'Europe/Paris', $admin->id);
        $a = $this->createEventUser('User A');
        $this->recordTimezoneSetting('Europe/Paris', 'America/Denver', $admin->id);
        $this->bus()->dispatch(new UpdateUserProfile($a->id, ['timezone' => 'America/Chicago'], $admin->id));
        $this->stripKeys();

        $this->artisan(self::USER_COMMAND, ['--execute' => true])->assertExitCode(0);

        $created = DB::table('stored_events')->where('event_class', 'user.created_manually')->where('aggregate_uuid', $a->id)->value('event_properties');
        $this->assertSame('Europe/Paris', json_decode($created, true)['attributes']['timezone']);
        $this->assertRebuildUnchanged();
    }

    public function test_external_hr_import_new_rows_get_the_timezone_and_existing_rows_do_not(): void
    {
        foreach (['display_name', 'email'] as $field) {
            $this->bus()->dispatch(new ChangeFieldAuthority($field, 'EXTERNAL_HR', 'csv', null));
        }
        $admin = $this->createEventUser('HR Admin');
        $existing = $this->createEventUser('HR Existing');
        $this->bus()->dispatch(new ApplyExternalHrImport((string) Str::uuid(), [
            ['external_subject_id' => 'hr-new', 'changes' => ['display_name' => 'HR New', 'email' => 'hr-new@example.com']],
            ['external_subject_id' => 'hr-existing', 'user_id' => $existing->id, 'changes' => ['display_name' => 'HR Existing 2']],
        ], $admin->id));
        DB::table('users')->where('email', 'hr-new@example.com')->update(['timezone' => 'Asia/Seoul']);
        $this->stripKeys();
        $this->assertSame(3, (new LegacyUserSettingsDetector)->count());

        $this->artisan(self::USER_COMMAND, ['--execute' => true])->assertExitCode(0);

        $rows = $this->payloadOf('external_hr.import_applied')['rows'];
        $this->assertTrue($rows[0]['is_new']);
        $this->assertSame('Asia/Seoul', $rows[0]['timezone']);
        $this->assertFalse($rows[1]['is_new']);
        $this->assertArrayNotHasKey('timezone', $rows[1]);
        $this->assertSame(0, (new LegacyUserSettingsDetector)->count());
        $this->assertRebuildUnchanged();
    }

    public function test_a_synced_event_that_does_not_create_the_user_uses_the_settings_history(): void
    {
        $user = $this->createEventUser('Synced Existing');
        DB::table('users')->where('id', $user->id)->update(['entra_user_id' => 'entra-ex']);
        $this->syncFromMs365([new MicrosoftGraphUser('entra-ex', 'Synced Existing', $user->email, null, null, true)]);
        DB::table('users')->where('id', $user->id)->update(['timezone' => 'Asia/Seoul']);
        $this->stripKeys();

        $this->artisan(self::USER_COMMAND, ['--execute' => true])->assertExitCode(0);

        // 新規作成にならない synced は、履歴(更新が無いのでライブの設定)。作成のイベントは、後に書くイベントが無いのでライブの利用者の値。
        $this->assertSame('Europe/London', $this->payloadOf('user.synced_from_ms365')['timezone']);
        $this->assertSame('Asia/Seoul', $this->payloadOf('user.created_manually')['attributes']['timezone']);
        $this->assertSame('tenant-A', $this->payloadOf('user.synced_from_ms365')['externalTenantId']);
        $this->assertRebuildUnchanged();
    }

    public function test_a_synced_event_that_creates_the_user_uses_the_live_timezone(): void
    {
        $this->syncFromMs365([new MicrosoftGraphUser('entra-new', '同期太郎', 'sync@example.com', null, null, true)]);
        DB::table('users')->where('entra_user_id', 'entra-new')->update(['timezone' => 'Asia/Seoul']);
        $this->stripKeys();

        $this->artisan(self::USER_COMMAND, ['--execute' => true])->assertExitCode(0);

        $this->assertSame('Asia/Seoul', $this->payloadOf('user.synced_from_ms365')['timezone']);
        $this->assertRebuildUnchanged();
    }

    // ---- テナントID ----

    public function test_a_missing_tenant_id_key_is_filled_with_an_explicit_null_when_the_live_value_is_null(): void
    {
        SystemSetting::current()->update(['m365_tenant_id' => null]);
        $this->bus()->dispatch(new RecordSsoLogin('entra-null', 'SSO Null', 'null@example.com'));
        $this->assertNull($this->payloadOf('user.created_from_sso_login')['externalTenantId']);
        $this->stripKeys();
        $this->assertArrayNotHasKey('externalTenantId', $this->payloadOf('user.created_from_sso_login'));

        $this->artisan(self::USER_COMMAND, ['--execute' => true])->assertExitCode(0);

        $payload = $this->payloadOf('user.created_from_sso_login');
        $this->assertArrayHasKey('externalTenantId', $payload);
        $this->assertNull($payload['externalTenantId']);
        $this->assertSame(0, (new LegacyUserSettingsDetector)->count());
        SystemSetting::current()->update(['m365_tenant_id' => 'tenant-B']);
        $this->assertRebuildUnchanged();
        $this->assertDatabaseHas('external_identities', ['external_subject_id' => 'entra-null', 'external_tenant_id' => null]);
    }

    public function test_a_null_tenant_id_is_overwritten_with_the_live_value_and_listed_in_the_dry_run(): void
    {
        $this->bus()->dispatch(new RecordSsoLogin('entra-ow', 'SSO Ow', 'ow@example.com'));
        // キーはあるが null で記録されたイベント(ライブは tenant-A)。キーは欠けていないので、検出器は陰性。
        $payload = $this->payloadOf('user.created_from_sso_login');
        $payload['externalTenantId'] = null;
        DB::table('stored_events')->where('event_class', 'user.created_from_sso_login')->update(['event_properties' => json_encode($payload)]);
        $this->assertSame(0, (new LegacyUserSettingsDetector)->count());
        $events = $this->storedEvents();

        $this->artisan(self::USER_COMMAND)
            ->expectsOutputToContain('テナントID上書き 1 件')
            ->expectsOutputToContain('entraUserId=entra-ow null → tenant-A')
            ->assertExitCode(0);
        $this->assertSame($events, $this->storedEvents());

        $this->artisan(self::USER_COMMAND, ['--execute' => true])->assertExitCode(0);

        $this->assertSame('tenant-A', $this->payloadOf('user.created_from_sso_login')['externalTenantId']);
        $this->assertRebuildUnchanged();
    }

    public function test_a_non_null_tenant_id_is_left_unchanged(): void
    {
        $this->bus()->dispatch(new RecordSsoLogin('entra-keep', 'SSO Keep', 'keep@example.com'));
        $payload = $this->payloadOf('user.created_from_sso_login');
        $payload['externalTenantId'] = 'tenant-OLD';
        DB::table('stored_events')->where('event_class', 'user.created_from_sso_login')->update(['event_properties' => json_encode($payload)]);

        $this->artisan(self::USER_COMMAND, ['--execute' => true])->expectsOutputToContain('対象なし')->assertExitCode(0);

        $this->assertSame('tenant-OLD', $this->payloadOf('user.created_from_sso_login')['externalTenantId']);
    }

    public function test_it_stops_when_the_live_external_identity_is_missing(): void
    {
        $this->bus()->dispatch(new RecordSsoLogin('entra-gone', 'SSO Gone', 'gone@example.com'));
        $this->stripKeys();
        DB::table('external_identities')->where('external_subject_id', 'entra-gone')->delete();
        $events = $this->storedEvents();

        $this->artisan(self::USER_COMMAND)
            ->expectsOutputToContain('補えない値: 1 件')
            ->expectsOutputToContain('entra-gone')
            ->assertExitCode(0);
        $this->artisan(self::USER_COMMAND, ['--execute' => true])->assertExitCode(1);

        $this->assertSame($events, $this->storedEvents());
        $this->assertSame([], $this->backupTables());
        $this->assertSame(0, $this->correctionLogCount('user_settings_at_replay'));
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_it_stops_when_the_timezone_cannot_be_restored(): void
    {
        $user = $this->createEventUser('No Row');
        $this->stripKeys();
        // ライブの利用者の行が無く、設定の履歴(更新イベントもライブの設定の行も)からも復元できない。
        Schema::disableForeignKeyConstraints();
        DB::table('users')->where('id', $user->id)->delete();
        Schema::enableForeignKeyConstraints();
        DB::table('system_settings')->delete();
        $events = $this->storedEvents();

        $this->artisan(self::USER_COMMAND, ['--execute' => true])->assertExitCode(1);

        $this->assertSame($events, $this->storedEvents());
        $this->assertSame([], $this->backupTables());
    }

    public function test_snapshots_make_the_command_refuse(): void
    {
        $this->createEventUser('Manual');
        $this->stripKeys();
        DB::table('snapshots')->insert([
            'aggregate_uuid' => (string) Str::uuid(), 'aggregate_version' => 1, 'state' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $events = $this->storedEvents();

        $this->artisan(self::USER_COMMAND, ['--execute' => true])->expectsOutputToContain('snapshots')->assertExitCode(1);

        $this->assertSame($events, $this->storedEvents());
        $this->assertSame([], $this->backupTables());
    }

    public function test_everything_is_rolled_back_when_the_rebuild_differs_from_the_live_data(): void
    {
        $user = $this->createEventUser('Manual');
        $this->stripKeys();
        // イベントを経ずに書かれたライブの値(作り直すと消える)。
        DB::table('users')->where('id', $user->id)->update(['department' => '直接更新']);
        $events = $this->storedEvents();

        $this->artisan(self::USER_COMMAND, ['--execute' => true])->expectsOutputToContain('検証に失敗')->assertExitCode(1);

        $this->assertSame($events, $this->storedEvents());
        $this->assertSame(0, $this->correctionLogCount('user_settings_at_replay'));
        $this->assertSame('直接更新', DB::table('users')->where('id', $user->id)->value('department'));
        $this->assertCount(1, $this->backupTables(), 'バックアップ表は残る');
        $this->assertSame(0, DB::transactionLevel());
    }

    // ---- 未補正の間の拒否 ----

    public function test_the_rebuild_and_the_correction_commands_are_refused_while_uncorrected_events_exist(): void
    {
        $this->createEventUser('Manual');
        $this->stripKeys();
        $events = $this->storedEvents();
        $users = DB::table('users')->count();

        $this->artisan('read-models:rebuild', ['--dry-run' => true])->expectsOutputToContain(self::USER_COMMAND)->assertExitCode(1);
        $this->artisan('read-models:rebuild')->expectsOutputToContain(self::USER_COMMAND)->assertExitCode(1);
        $this->artisan('projections:rebuild')->expectsOutputToContain(self::USER_COMMAND)->assertExitCode(1);
        $this->artisan(self::BULK_COMMAND)->expectsOutputToContain(self::COMBINED_COMMAND)->assertExitCode(1);
        $this->artisan(self::BULK_COMMAND, ['--execute' => true])->expectsOutputToContain(self::COMBINED_COMMAND)->assertExitCode(1);
        $this->artisan('events:rename-boolean-properties')->expectsOutputToContain(self::COMBINED_COMMAND)->assertExitCode(1);
        $this->artisan('events:rename-boolean-properties', ['--execute' => true])->expectsOutputToContain(self::COMBINED_COMMAND)->assertExitCode(1);

        $this->assertSame($events, $this->storedEvents());
        $this->assertSame($users, DB::table('users')->count(), '拒否したので利用者は消えていない');

        $this->artisan(self::USER_COMMAND, ['--execute' => true])->assertExitCode(0);
        $this->artisan('read-models:rebuild', ['--dry-run' => true])->assertExitCode(0);
    }

    // ---- 一括操作の補正とまとめた補正 ----

    /** 一括操作を適用して取り消し、旧コードの状態(対象行のidと取消イベントのidがランダム)にする。 */
    private function legacyBulkOperation(User $admin, User $worker): string
    {
        $this->bus()->dispatch(new CreateDefaultWorkStyle([], $admin->id));
        $workStyleId = (string) DB::table('work_styles')->value('id');
        $entries = array_map(fn (string $date) => [
            'user_id' => $worker->id, 'work_date' => $date, 'work_style_id' => $workStyleId, 'schedule_state' => 'WORK',
        ], ['2026-12-01', '2026-12-02']);
        $operationId = $this->bus()->dispatch(new ApplyCalendarBulkOperation('bulk_edit', ['entries' => $entries], 'skip_existing', 'テスト', $admin->id))->id;
        $this->bus()->dispatch(new RevertCalendarBulkOperation($operationId, $admin->id));

        $old = [];
        foreach (DB::table(self::TARGETS)->where('calendar_bulk_operation_id', $operationId)->orderBy('id')->get() as $row) {
            $legacyId = (string) Str::uuid();
            DB::table(self::TARGETS)->where('id', $row->id)->update(['id' => $legacyId]);
            $old[$row->id] = $legacyId;
        }
        foreach (DB::table('stored_events')->where('event_class', 'calendar_bulk_operation.reverted')->where('aggregate_uuid', $operationId)->get() as $event) {
            $payload = json_decode($event->event_properties, true);
            foreach (['revertedTargetIds', 'excludedTargetIds'] as $key) {
                $payload[$key] = array_map(fn (string $id) => $old[$id], $payload[$key]);
            }
            DB::table('stored_events')->where('id', $event->id)->update(['event_properties' => json_encode($payload)]);
        }

        return $operationId;
    }

    /** 一括操作と利用者の両方が旧データのDB。 */
    private function buildBothLegacy(): string
    {
        $admin = $this->createEventAdmin();
        $worker = $this->createEventUser('Bulk Worker');
        $this->bus()->dispatch(new RecordSsoLogin('entra-both', 'SSO Both', 'both@example.com'));
        $operationId = $this->legacyBulkOperation($admin, $worker);
        $this->stripKeys();

        return $operationId;
    }

    public function test_each_single_correction_refuses_while_the_other_is_needed(): void
    {
        $this->buildBothLegacy();
        $events = $this->storedEvents();
        $this->assertGreaterThan(0, (new LegacyUserSettingsDetector)->count());
        $this->assertSame(1, (new LegacyCalendarBulkOperationTargetIdDetector)->count());

        $this->artisan(self::USER_COMMAND)->expectsOutputToContain(self::COMBINED_COMMAND)->assertExitCode(1);
        $this->artisan(self::USER_COMMAND, ['--execute' => true])->expectsOutputToContain(self::COMBINED_COMMAND)->assertExitCode(1);
        $this->artisan(self::BULK_COMMAND, ['--execute' => true])->expectsOutputToContain(self::COMBINED_COMMAND)->assertExitCode(1);

        $this->assertSame($events, $this->storedEvents());
        $this->assertSame([], $this->backupTables());
    }

    public function test_the_combined_dry_run_shows_both_results_and_changes_nothing(): void
    {
        $operationId = $this->buildBothLegacy();
        $events = $this->storedEvents();
        $targets = DB::table(self::TARGETS)->orderBy('id')->pluck('id')->all();

        $this->artisan(self::COMBINED_COMMAND)
            ->expectsOutputToContain('一括操作の取消イベントの対象行id')
            ->expectsOutputToContain('取消イベント 1 件')
            ->expectsOutputToContain('利用者の設定値')
            ->expectsOutputToContain('試し実行(データは変更していません')
            ->assertExitCode(0);

        $this->assertSame($events, $this->storedEvents());
        $this->assertSame($targets, DB::table(self::TARGETS)->orderBy('id')->pluck('id')->all());
        $this->assertSame([], $this->backupTables());
        $this->assertNotEmpty($operationId);
    }

    public function test_the_combined_execute_corrects_both_rebuilds_once_and_passes_the_verification(): void
    {
        $operationId = $this->buildBothLegacy();
        $users = $this->tableRows('users');
        $identities = $this->tableRows('external_identities');
        $contents = DB::table(self::TARGETS)->get()->map(function ($row) {
            $row = (array) $row;
            unset($row['id'], $row['created_at']);

            return json_encode($row);
        })->sort()->values()->all();

        $this->artisan(self::COMBINED_COMMAND, ['--execute' => true])->expectsOutputToContain('検証に成功しました')->assertExitCode(0);
        $this->assertSame(0, DB::transactionLevel());

        // 一括操作: 取消イベントのidが新しいid。対象行の内容は同じ。
        $reverted = json_decode((string) DB::table('stored_events')->where('event_class', 'calendar_bulk_operation.reverted')->value('event_properties'), true);
        $this->assertEqualsCanonicalizing(
            [CalendarBulkOperationProjector::targetId($operationId, 0), CalendarBulkOperationProjector::targetId($operationId, 1)],
            $reverted['revertedTargetIds'],
        );
        $newContents = DB::table(self::TARGETS)->get()->map(function ($row) {
            $row = (array) $row;
            unset($row['id'], $row['created_at']);

            return json_encode($row);
        })->sort()->values()->all();
        $this->assertSame($contents, $newContents);
        // 利用者: users・external_identities は同じ。
        $this->assertEquals($users, $this->tableRows('users'));
        $this->assertEquals($identities, $this->tableRows('external_identities'));
        $this->assertSame(0, (new LegacyUserSettingsDetector)->count());
        $this->assertSame(0, (new LegacyCalendarBulkOperationTargetIdDetector)->count());
        $this->assertSame(0, (new ReadModelRebuilder)->rebuild(true)['changed_tables']);

        // バックアップ表は2つ(一括操作・利用者)、補正ログは両方のキーで残る。
        $this->assertCount(2, $this->backupTables());
        $this->assertSame(1, $this->correctionLogCount('calendar_bulk_operation_target_ids'));
        $this->assertGreaterThan(0, $this->correctionLogCount('user_settings_at_replay'));

        // 冪等。
        $events = $this->storedEvents();
        $this->artisan(self::COMBINED_COMMAND)->expectsOutputToContain('対象なし')->assertExitCode(0);
        $this->artisan(self::COMBINED_COMMAND, ['--execute' => true])->expectsOutputToContain('対象なし')->assertExitCode(0);
        $this->assertSame($events, $this->storedEvents());
        $this->assertCount(2, $this->backupTables());
    }

    public function test_the_combined_command_works_when_only_the_user_settings_are_legacy(): void
    {
        $this->createEventUser('Manual');
        $this->stripKeys();

        $this->artisan(self::COMBINED_COMMAND, ['--execute' => true])->assertExitCode(0);

        $this->assertSame(0, (new LegacyUserSettingsDetector)->count());
        $this->assertCount(1, $this->backupTables());
        $this->assertSame(0, (new ReadModelRebuilder)->rebuild(true)['changed_tables']);
    }

    public function test_the_combined_command_changes_nothing_when_one_side_cannot_be_corrected(): void
    {
        $this->buildBothLegacy();
        DB::table('external_identities')->where('external_subject_id', 'entra-both')->delete();
        $events = $this->storedEvents();
        $targets = DB::table(self::TARGETS)->orderBy('id')->pluck('id')->all();

        $this->artisan(self::COMBINED_COMMAND)->expectsOutputToContain('補えない値: 1 件')->assertExitCode(0);
        $this->artisan(self::COMBINED_COMMAND, ['--execute' => true])->assertExitCode(1);

        $this->assertSame($events, $this->storedEvents());
        $this->assertSame($targets, DB::table(self::TARGETS)->orderBy('id')->pluck('id')->all());
        $this->assertSame([], $this->backupTables());
        $this->assertSame(0, $this->correctionLogCount('calendar_bulk_operation_target_ids'));
        $this->assertSame(0, $this->correctionLogCount('user_settings_at_replay'));
    }

    public function test_the_combined_command_rolls_everything_back_when_another_table_changes(): void
    {
        $this->buildBothLegacy();
        $worker = User::query()->where('name', 'Bulk Worker')->firstOrFail();
        DB::table('users')->where('id', $worker->id)->update(['department' => '直接更新']);
        $events = $this->storedEvents();
        $targets = DB::table(self::TARGETS)->orderBy('id')->pluck('id')->all();

        $this->artisan(self::COMBINED_COMMAND, ['--execute' => true])->expectsOutputToContain('検証に失敗')->assertExitCode(1);

        $this->assertSame($events, $this->storedEvents());
        $this->assertSame($targets, DB::table(self::TARGETS)->orderBy('id')->pluck('id')->all(), '一括操作の対象行も元のまま');
        $this->assertSame(0, $this->correctionLogCount('calendar_bulk_operation_target_ids'));
        $this->assertSame(0, $this->correctionLogCount('user_settings_at_replay'));
        $this->assertSame(0, DB::transactionLevel());
    }
}
