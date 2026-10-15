<?php

namespace Tests\Feature\EventSourcing;

use App\Domain\EventSourcing\Rebuild\ReadModelTableLister;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * 照合による補完(論点19 決定1)のシナリオテスト。シーダー相当の行(イベントを経ずに作られた行)を直接 insert し、
 * いくつかの更新イベントだけを stored_events に入れた状態から、試し実行 → --apply(復元可否を含む自己整合性)→ --swap →
 * 参照マスタ以外の全ReadModelを空にして全Projectorを replay → 各テーブルの行が元と一致する(created_at も比べられるものは比べる)
 * ところまでを通す。補完の規則の判定は tests/Unit/EventSourcing/Rebuild/SeededDataReconcilerTest.php にある。
 */
class SeededDataRebuildScenarioTest extends TestCase
{
    use RefreshDatabase;

    private const AT = '2026-09-01 10:00:00';

    private const U1 = '11111111-1111-4111-8111-111111111111';

    private const U2 = '22222222-2222-4222-8222-222222222222';

    private const G1 = '33333333-3333-4333-8333-333333333333';

    private const G2 = '44444444-4444-4444-8444-444444444444';

    private const A1 = '55555555-5555-4555-8555-555555555551';

    private const A2 = '55555555-5555-4555-8555-555555555552';

    private const CALENDAR = '66666666-6666-4666-8666-666666666666';

    private const YEAR = '77777777-7777-4777-8777-777777777777';

    private const SOURCE = '88888888-8888-4888-8888-888888888888';

    /** @var list<int> */
    private array $permissionIds = [];

    /** @var list<int> */
    private array $featureIds = [];

    /** @var array<string, int> */
    private array $masterCounts = [];

    /** @var array<string, int> */
    private array $versions = [];

    private int $nextId = 1;

    /** 祝日の同期イベントが(旧形式の)自動採番idで指す日(2026-04-02)の、作り直し前のid。 */
    private int $holidayDayId = 0;

    public function test_seeded_rows_are_reconciled_into_the_rebuilt_events_and_replay_to_the_same_rows(): void
    {
        $this->insertSeededRows();
        $this->insertUpdateEvents();
        $before = $this->snapshot();
        $createdAtBefore = $this->createdAtSnapshot();

        $this->artisan('leave:rebuild-event-store')
            ->expectsOutputToContain('照合による補完')
            ->assertExitCode(0);
        // 復元可否(現行のイベントクラスへ復元できること)を含む自己整合性の検査が通る。
        $this->artisan('leave:rebuild-event-store', ['--apply' => true])->assertExitCode(0);
        $this->artisan('leave:rebuild-event-store', ['--swap' => true])->assertExitCode(0);

        $this->assertDerivedEvents();
        $afterSwap = $this->eventSummary();

        $this->emptyReadModels();
        $this->assertSame(0, DB::table('users')->count());
        $beforeReplay = $this->eventSummary();
        $this->assertSame($afterSwap, $beforeReplay, 'ReadModelを空にしたことで stored_events が変わった(空にする一覧に stored_events が入っている疑い)。swap後: '.$afterSwap.' / replay前: '.$beforeReplay);
        $exitCode = Artisan::call('event-sourcing:replay', ['--force' => true]);
        $this->assertSame(0, $exitCode, Artisan::output());

        $empty = array_keys(array_filter($this->snapshot(), fn (array $rows) => $rows === []));
        if ($empty !== []) {
            $this->fail('replay後に空のままの表: '.implode(', ', $empty)
                ."\nswap後: ".$afterSwap."\nreplay前: ".$beforeReplay."\nreplay後: ".$this->eventSummary()
                ."\nreplay出力: ".Artisan::output()
                ."\nevent_class一覧: ".implode(', ', DB::table('stored_events')->orderBy('id')->pluck('event_class')->all()));
        }

        $this->assertEquals($before, $this->snapshot());
        $this->assertEquals($createdAtBefore, $this->createdAtSnapshot());
        // 暦の既定は再生後も同じ(導出した default_changed で true になる)。
        $this->assertTrue((bool) DB::table('company_calendars')->where('id', self::CALENDAR)->value('is_default'));
        // 日の行の id は再生で変わるが、祝日の同期は年の id と日付で正しい日(2026-04-02)に付く(日の出典が記録される)。
        $this->assertNotSame($this->holidayDayId, (int) DB::table('company_calendar_days')->whereDate('date', '2026-04-02')->value('id'));
        $sources = DB::table('company_calendar_day_sources')
            ->join('company_calendar_days', 'company_calendar_days.id', '=', 'company_calendar_day_sources.company_calendar_day_id')
            ->where('company_calendar_day_sources.source_type', 'holiday_sync')
            ->where('company_calendar_day_sources.source_ref', self::SOURCE)
            ->pluck('company_calendar_days.date')->map(fn ($d) => substr((string) $d, 0, 10))->all();
        $this->assertSame(['2026-04-02'], $sources);
        // 参照マスタは空にしておらず、そのまま残っている。
        $this->assertSame($this->masterCounts['permissions'], DB::table('permissions')->count());
        $this->assertSame($this->masterCounts['features'], DB::table('features')->count());
    }

    public function test_a_second_rebuild_after_replay_derives_nothing_more(): void
    {
        $this->insertSeededRows();
        $this->insertUpdateEvents();
        $this->artisan('leave:rebuild-event-store', ['--apply' => true])->assertExitCode(0);
        $this->artisan('leave:rebuild-event-store', ['--swap' => true])->assertExitCode(0);
        $this->emptyReadModels();
        Artisan::call('event-sourcing:replay', ['--force' => true]);
        $eventsBefore = DB::table('stored_events')->count();

        // 再生後のReadModelはイベントと一致するため、もう一度試し実行しても導出イベントは増えない(created_at の違いは比べない)。
        $result = app(\App\Domain\EventSourcing\Rebuild\EventStoreRebuilder::class)->rebuild(
            app(\App\Domain\EventSourcing\Rebuild\EventStoreRebuildWriter::class)->readRows(),
            [],
            [],
            app(\App\Domain\EventSourcing\Rebuild\EventStoreRebuildWriter::class)->readSeededData(),
        );

        $this->assertTrue($result->ok, json_encode($result->unsupported, JSON_UNESCAPED_UNICODE));
        $this->assertSame([], $result->report['reconcile']['derived']);
        $this->assertSame($eventsBefore, count($result->rows));
    }

    /** 診断用: stored_events の件数とイベント種別ごとの件数。 */
    private function eventSummary(): string
    {
        $counts = DB::table('stored_events')->selectRaw('event_class, count(*) as n')->groupBy('event_class')->orderBy('event_class')->pluck('n', 'event_class')->all();

        return 'total='.array_sum($counts).' '.json_encode($counts, JSON_UNESCAPED_UNICODE);
    }

    /** 導出イベントの位置・内容(作り直し後の stored_events)。 */
    private function assertDerivedEvents(): void
    {
        $events = DB::table('stored_events')->orderBy('id')->get()->map(fn ($r) => [
            'id' => (int) $r->id, 'class' => $r->event_class, 'aggregate' => $r->aggregate_uuid, 'version' => (int) $r->aggregate_version,
            'payload' => json_decode($r->event_properties, true), 'created_at' => $r->created_at,
        ])->all();
        $classes = array_column($events, 'class');

        // id は連番、版は集約ごとに1から連番。
        $this->assertSame(range(1, count($events)), array_column($events, 'id'));
        $byAggregate = [];
        foreach ($events as $e) {
            $byAggregate[$e['aggregate']][] = $e['version'];
        }
        foreach ($byAggregate as $versions) {
            $this->assertSame(range(1, count($versions)), $versions);
        }

        // 利用者 U1(イベント無し)は列の先頭側、U2 は最初のイベント(user.logged_in)の直前。
        $u2 = array_values(array_filter($events, fn (array $e) => $e['aggregate'] === self::U2));
        $this->assertSame(['user.migrated_from_legacy', 'user.logged_in'], array_column($u2, 'class'));
        $u1Created = array_values(array_filter($events, fn (array $e) => $e['aggregate'] === self::U1 && $e['class'] === 'user.migrated_from_legacy'));
        $this->assertCount(1, $u1Created);
        $this->assertSame('2026-01-04 00:00:00', $u1Created[0]['created_at']);

        // 作成の補完: group_type 2・field_authority 2・group 2・role 2・role_assignment 2・membership 2・external_identity 2 ほか。
        $this->assertSame(2, count(array_keys($classes, 'group_type.created', true)));
        $this->assertSame(1, count(array_keys($classes, 'group_type.updated', true)));
        $this->assertSame(2, count(array_keys($classes, 'group.created', true)));
        $this->assertSame(2, count(array_keys($classes, 'role.created', true)));
        $this->assertSame(2, count(array_keys($classes, 'role_assignment.created', true)));
        $this->assertSame(2, count(array_keys($classes, 'membership.added', true)));
        $this->assertSame(2, count(array_keys($classes, 'external_identity.linked', true)));
        $this->assertSame(2, count(array_keys($classes, 'user.field_authority_changed', true)));
        $this->assertSame(2, count(array_keys($classes, 'feature.assigned_to_group', true)));
        $this->assertSame(1, count(array_keys($classes, 'company_calendar_year.created', true)));
        $this->assertSame(1, count(array_keys($classes, 'company_calendar_year.published', true)));

        // 最終状態が違った role 2 の権限は、最後のイベント(role.permissions_changed)の直後に全置換が補われる。
        $role2 = Uuid::uuid5(Uuid::NAMESPACE_URL, 'role:employee')->toString();
        $role2Events = array_values(array_filter($events, fn (array $e) => $e['aggregate'] === $role2));
        $this->assertSame(['role.created', 'role.updated', 'role.permissions_changed', 'role.permissions_changed'], array_column($role2Events, 'class'));
        $this->assertSame([$this->permissionIds[0]], $role2Events[3]['payload']['permissionIds']);

        // 暦の既定(イベントを経ずに設定された is_default)は company_calendar.default_changed(操作者null)として暦の集約に導出される。
        $defaults = array_values(array_filter($events, fn (array $e) => $e['class'] === 'company_calendar.default_changed'));
        $this->assertCount(1, $defaults);
        $this->assertSame(self::CALENDAR, $defaults[0]['aggregate']);
        $this->assertNull($defaults[0]['payload']['previousDefaultCompanyCalendarId']);
        $this->assertNull($defaults[0]['payload']['changedByUserId']);

        // 祝日の同期イベントの dayChanges には、作り直し前の日の行から companyCalendarYearId・date が補われる(消えた日はそのまま)。
        $synced = array_values(array_filter($events, fn (array $e) => $e['class'] === 'holiday_calendar_source.synced'));
        $this->assertCount(1, $synced);
        $changes = $synced[0]['payload']['dayChanges'];
        $this->assertSame(self::YEAR, $changes[0]['companyCalendarYearId']);
        $this->assertSame('2026-04-02', $changes[0]['date']);
        $this->assertArrayNotHasKey('companyCalendarYearId', $changes[1]);
        $this->assertSame('2026-04-29', $changes[1]['date']);

        // days_updated の旧集約UUID(company_calendars.id)は年の id へ付け替わっている。
        $this->assertSame(0, DB::table('stored_events')->where('aggregate_uuid', self::CALENDAR)->where('event_class', 'company_calendar_year.days_updated')->count());
        $this->assertGreaterThanOrEqual(2, DB::table('stored_events')->where('aggregate_uuid', self::YEAR)->where('event_class', 'company_calendar_year.days_updated')->count());
    }

    /**
     * 参照マスタの行のid(codeで既存の行を探し、無ければ作る)。ReadModelではないため空にも補完にもしない。
     *
     * @param  list<string>  $codes
     * @param  callable(string): array<string, mixed>  $make
     * @return list<int>
     */
    private function masterIds(string $table, array $codes, callable $make): array
    {
        $ids = [];
        foreach ($codes as $code) {
            $id = DB::table($table)->where('code', $code)->value('id');
            $ids[] = (int) ($id ?? DB::table($table)->insertGetId($make($code)));
        }

        return $ids;
    }

    private function insertSeededRows(): void
    {
        $now = '2026-01-01 00:00:00';
        // 参照マスタ(カタログ)は既に行があるため、固定のidで insert せず、既存の行を使う(無ければ作る)。
        $this->permissionIds = $this->masterIds('permissions', ['p.view', 'p.edit'], fn (string $code) => ['code' => $code, 'resource' => 'p', 'action' => substr($code, 2), 'created_at' => $now, 'updated_at' => $now]);
        $this->featureIds = $this->masterIds('features', ['f.one', 'f.two'], fn (string $code) => ['code' => $code, 'name' => $code, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $this->masterCounts = ['permissions' => DB::table('permissions')->count(), 'features' => DB::table('features')->count()];

        $type = fn (int $id, string $code, bool $system, string $status, string $at) => [
            'id' => $id, 'code' => $code, 'name' => $code, 'display_order' => $id * 10, 'is_system' => $system, 'status' => $status,
            'membership_limit_type' => 'unlimited', 'max_memberships_per_user' => null, 'primary_membership_required' => false, 'max_primary_memberships' => null,
            'created_at' => $at, 'updated_at' => $at,
        ];
        DB::table('group_types')->insert([$type(1, 'department', true, 'active', '2026-01-01 00:00:00'), $type(2, 'team', false, 'inactive', '2026-01-02 00:00:00')]);

        DB::table('users')->insert(['id' => self::U1, 'entra_user_id' => 's1', 'name' => '管理者', 'email' => 'u1@example.test', 'account_status' => 'active', 'source_type' => 'microsoft_entra',
            'employment_status' => 'active', 'timezone' => 'Asia/Tokyo', 'hire_date' => '2020-04-01', 'created_at' => '2026-01-04 00:00:00', 'updated_at' => '2026-01-04 00:00:00']);
        DB::table('users')->insert(['id' => self::U2, 'entra_user_id' => null, 'name' => '社員', 'email' => 'u2@example.test', 'account_status' => 'active', 'source_type' => 'local',
            'employment_status' => 'active', 'timezone' => 'Asia/Tokyo', 'last_login_at' => '2026-08-02 09:00:00', 'paid_leave_auto_grant_enabled' => false, 'created_at' => '2026-01-05 00:00:00', 'updated_at' => '2026-01-05 00:00:00']);

        // G1 は種別 1 の親、G2 はその子(G2 にはこの後 group.updated のイベントだけがある)。
        DB::table('groups')->insert([
            ['id' => self::G1, 'group_type_id' => 1, 'name' => '本社', 'code' => 'hq', 'description' => null, 'parent_group_id' => null, 'status' => 'active', 'created_at' => '2026-01-06 00:00:00', 'updated_at' => '2026-01-06 00:00:00'],
            ['id' => self::G2, 'group_type_id' => 1, 'name' => 'チームB', 'code' => 'g2', 'description' => null, 'parent_group_id' => self::G1, 'status' => 'active', 'created_at' => '2026-01-07 00:00:00', 'updated_at' => '2026-01-07 00:00:00'],
        ]);

        // role 1(is_system、イベント無し)と role 2(role.updated・role.permissions_changed のイベントだけがある)。
        DB::table('roles')->insert([
            ['id' => 1, 'code' => 'admin', 'name' => '管理者', 'description' => null, 'is_system' => true, 'status' => 'active', 'created_at' => '2026-01-08 00:00:00', 'updated_at' => '2026-01-08 00:00:00'],
            ['id' => 2, 'code' => 'employee', 'name' => '社員(改)', 'description' => 'd', 'is_system' => true, 'status' => 'active', 'created_at' => '2026-01-09 00:00:00', 'updated_at' => '2026-01-09 00:00:00'],
        ]);
        DB::table('permission_role')->insert([
            ['role_id' => 1, 'permission_id' => $this->permissionIds[0]], ['role_id' => 1, 'permission_id' => $this->permissionIds[1]], ['role_id' => 2, 'permission_id' => $this->permissionIds[0]],
        ]);
        DB::table('role_features')->insert([['role_id' => 1, 'feature_id' => $this->featureIds[0], 'created_at' => '2026-01-10 00:00:00', 'updated_at' => '2026-01-10 00:00:00']]);
        DB::table('role_assignments')->insert([
            ['id' => self::A1, 'subject_type' => 'user', 'subject_id' => self::U1, 'role_id' => 1, 'scope_type' => 'company', 'scope_group_id' => null, 'include_descendants' => false,
                'starts_at' => null, 'ends_at' => null, 'status' => 'active', 'assigned_by' => null, 'created_at' => '2026-01-11 00:00:00', 'updated_at' => '2026-01-11 00:00:00'],
            ['id' => self::A2, 'subject_type' => 'user', 'subject_id' => self::U2, 'role_id' => 2, 'scope_type' => 'group', 'scope_group_id' => self::G2, 'include_descendants' => true,
                'starts_at' => '2026-04-01 00:00:00', 'ends_at' => '2027-03-31 00:00:00', 'status' => 'active', 'assigned_by' => self::U1, 'created_at' => '2026-01-12 00:00:00', 'updated_at' => '2026-01-12 00:00:00'],
        ]);
        DB::table('group_feature_assignments')->insert([
            ['group_id' => self::G1, 'feature_id' => $this->featureIds[0], 'assigned_by' => self::U1, 'created_at' => '2026-01-13 00:00:00', 'updated_at' => '2026-01-13 00:00:00'],
            ['group_id' => self::G2, 'feature_id' => $this->featureIds[1], 'assigned_by' => null, 'created_at' => '2026-01-14 00:00:00', 'updated_at' => '2026-01-14 00:00:00'],
        ]);
        DB::table('memberships')->insert([
            ['user_id' => self::U1, 'group_id' => self::G1, 'membership_kind' => 'primary', 'is_primary' => true, 'created_by' => self::U1, 'created_at' => '2026-01-15 00:00:00', 'updated_at' => '2026-01-15 00:00:00'],
            ['user_id' => self::U2, 'group_id' => self::G2, 'membership_kind' => 'member', 'is_primary' => false, 'created_by' => null, 'created_at' => '2026-01-16 00:00:00', 'updated_at' => '2026-01-16 00:00:00'],
        ]);
        DB::table('external_identities')->insert([
            ['user_id' => self::U1, 'provider' => 'MICROSOFT_ENTRA', 'external_tenant_id' => 'tenant', 'external_subject_id' => 's1', 'external_code' => null, 'email' => 'u1@example.test',
                'status' => 'active', 'linked_at' => '2026-01-17 00:00:00', 'last_synced_at' => null, 'created_at' => '2026-01-17 00:00:00', 'updated_at' => '2026-01-17 00:00:00'],
            ['user_id' => self::U2, 'provider' => 'EXTERNAL_HR', 'external_tenant_id' => null, 'external_subject_id' => 'E2', 'external_code' => 'C2', 'email' => null,
                'status' => 'active', 'linked_at' => '2026-01-18 00:00:00', 'last_synced_at' => null, 'created_at' => '2026-01-18 00:00:00', 'updated_at' => '2026-01-18 00:00:00'],
        ]);
        DB::table('field_authorities')->insert([
            ['field_key' => 'email', 'authority_type' => 'LOCAL', 'provider' => null, 'created_at' => '2026-01-19 00:00:00', 'updated_at' => '2026-01-19 00:00:00'],
            ['field_key' => 'name', 'authority_type' => 'EXTERNAL', 'provider' => 'MICROSOFT_ENTRA', 'created_at' => '2026-01-20 00:00:00', 'updated_at' => '2026-01-20 00:00:00'],
        ]);

        DB::table('company_calendars')->insert([
            'id' => self::CALENDAR, 'name' => '本社カレンダー', 'week_starts_on' => 1, 'fiscal_year_start_month' => 4, 'fiscal_year_start_day' => 1,
            // マイグレーション(2026_08_10_020900)がイベントを経ずに設定した既定の暦。イベントには company_calendar.default_changed が無い。
            'is_default' => true,
            'created_at' => '2026-01-21 00:00:00', 'updated_at' => '2026-01-21 00:00:00',
        ]);
        DB::table('company_calendar_years')->insert([
            'id' => self::YEAR, 'company_calendar_id' => self::CALENDAR, 'fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published',
            'generated_from' => 'manual', 'published_at' => '2026-03-31 09:00:00', 'published_by_user_id' => self::U1, 'created_at' => '2026-01-22 00:00:00', 'updated_at' => '2026-03-31 09:00:00',
        ]);
        $day = fn (string $date, string $type, bool $working, bool $legal, string $state, string $at) => [
            'calendar_id' => self::YEAR, 'date' => $date, 'day_type' => $type, 'is_working_day' => $working, 'is_legal_holiday' => $legal, 'is_company_holiday' => ! $working && ! $legal,
            'is_public_holiday' => false, 'public_holiday_name' => null, 'schedule_state' => $state, 'note' => null, 'created_at' => $at, 'updated_at' => $at,
        ];
        DB::table('company_calendar_days')->insert([
            $day('2026-04-01', 'weekday', true, false, 'WORK', self::AT),
            $day('2026-04-02', 'company_holiday', false, false, 'OFF', '2026-02-01 00:00:00'),
            $day('2026-04-03', 'legal_holiday', false, true, 'OFF', '2026-02-02 00:00:00'),
        ]);
        // 2026-04-02 は祝日の同期(holiday_calendar_source.synced)で祝日になった日。
        DB::table('company_calendar_days')->whereDate('date', '2026-04-02')->update(['is_public_holiday' => true, 'public_holiday_name' => '春の日']);
        $this->holidayDayId = (int) DB::table('company_calendar_days')->whereDate('date', '2026-04-02')->value('id');
    }

    /** いくつかの更新イベントだけ(作成イベントが無い集約・旧集約UUIDの days_updated・最終状態が違う権限)。 */
    private function insertUpdateEvents(): void
    {
        $this->insertEvent(self::CALENDAR, 'company_calendar.created', [
            'name' => '本社カレンダー', 'weekStartsOn' => 1, 'fiscalYearStartMonth' => 4, 'fiscalYearStartDay' => 1, 'createdByUserId' => self::U1,
            'weekdayHolidayPattern' => null, 'holidayCalendarSourceId' => null, 'allowDailyHolidayOverride' => null,
        ]);
        // マイグレーションで年を新設する前に記録された days_updated(集約UUIDは company_calendars.id のまま)。
        $this->insertEvent(self::CALENDAR, 'company_calendar_year.days_updated', ['days' => [
            ['date' => '2026-04-01', 'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false, 'note' => null],
        ], 'updatedByUserId' => null]);
        $this->insertEvent(self::G2, 'group.updated', ['name' => 'チームB', 'code' => 'g2', 'description' => null, 'parentGroupId' => self::G1, 'status' => 'active', 'actorUserId' => null]);
        $role2 = Uuid::uuid5(Uuid::NAMESPACE_URL, 'role:employee')->toString();
        $this->insertEvent($role2, 'role.updated', ['roleId' => 2, 'name' => '社員(改)', 'description' => 'd', 'status' => 'active', 'actorUserId' => null]);
        $this->insertEvent($role2, 'role.permissions_changed', ['roleId' => 2, 'permissionIds' => [$this->permissionIds[0], $this->permissionIds[1]], 'actorUserId' => null]);
        $this->insertEvent(self::U2, 'user.logged_in', ['wasFirstLogin' => true, 'loggedInAt' => '2026-08-02 09:00:00']);

        // 祝日の同期: 日を自動採番の company_calendar_day_id(と date)で指す旧形式。年の id は無い。2件目は既に無い日(補えない)。
        $this->insertEvent(self::SOURCE, 'holiday_calendar_source.registered', ['name' => '祝日', 'icsUrl' => 'https://example.test/holidays.ics', 'registeredByUserId' => self::U1, 'sourceKind' => 'url', 'uploadedIcsPath' => null, 'uploadedIcsFilename' => null]);
        $this->insertEvent(self::SOURCE, 'holiday_calendar_source.synced', [
            'eventChanges' => [],
            'dayChanges' => [
                ['company_calendar_day_id' => $this->holidayDayId, 'date' => '2026-04-02', 'is_public_holiday' => true, 'public_holiday_name' => '春の日', 'previous_is_public_holiday' => false, 'previous_public_holiday_name' => null],
                ['company_calendar_day_id' => 987654, 'date' => '2026-04-29', 'is_public_holiday' => true, 'public_holiday_name' => '消えた日', 'previous_is_public_holiday' => false, 'previous_public_holiday_name' => null],
            ],
            'protectedConflicts' => [],
            'syncedByUserId' => null,
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private function insertEvent(string $aggregate, string $class, array $payload): void
    {
        $this->versions[$aggregate] = ($this->versions[$aggregate] ?? 0) + 1;
        DB::table('stored_events')->insert([
            'id' => $this->nextId++,
            'aggregate_uuid' => $aggregate,
            'aggregate_version' => $this->versions[$aggregate],
            'event_version' => 1,
            'event_class' => $class,
            'event_properties' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'meta_data' => json_encode([
                'aggregate-root-uuid' => $aggregate,
                'aggregate-root-version' => $this->versions[$aggregate],
                'stored-event-id' => $this->nextId - 1,
                'created-at' => self::AT,
            ]),
            'created_at' => self::AT,
        ]);
    }

    /** 全ReadModel(leave:rebuild-event-store --list-readmodels の一覧。参照マスタ・認証情報は除いてある)を空にする。 */
    private function emptyReadModels(): void
    {
        $tables = array_keys((new ReadModelTableLister)->tables(app_path('Domain')));
        $this->assertContains('users', $tables);
        $this->assertContains('company_calendar_days', $tables);

        // 外部キー(restrict)の依存で消せない表は、後回しにして繰り返す(テストはトランザクション内のため、SQLiteではPRAGMAで外部キーを切れない)。
        $pending = array_values(array_filter($tables, fn (string $table) => Schema::hasTable($table)));
        for ($pass = 0; $pass < 10 && $pending !== []; $pass++) {
            $failed = [];
            foreach ($pending as $table) {
                try {
                    DB::table($table)->delete();
                } catch (QueryException) {
                    $failed[] = $table;
                }
            }
            $pending = $failed;
        }
        $this->assertSame([], $pending, '空にできなかったReadModel');
    }

    /**
     * 比較用のReadModel(日時列・自動採番のidを除く)。
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function snapshot(): array
    {
        $dateColumns = ['hire_date', 'termination_date', 'usage_start_date', 'date', 'starts_on', 'ends_on'];
        $spec = [
            'group_types' => [['id'], []],
            'groups' => [['code'], []],
            'roles' => [['id'], []],
            'permission_role' => [['role_id', 'permission_id'], ['id']],
            'role_features' => [['role_id', 'feature_id'], ['id']],
            'role_assignments' => [['id'], []],
            'group_feature_assignments' => [['group_id', 'feature_id'], ['id']],
            'users' => [['id'], []],
            'memberships' => [['user_id', 'group_id'], ['id']],
            'external_identities' => [['provider', 'external_subject_id'], ['id']],
            'field_authorities' => [['field_key'], ['id']],
            'company_calendar_years' => [['id'], []],
            'company_calendar_days' => [['date'], ['id']],
        ];

        $result = [];
        foreach ($spec as $table => [$order, $drop]) {
            $rows = [];
            $query = DB::table($table);
            foreach ($order as $column) {
                $query->orderBy($column);
            }
            foreach ($query->get() as $row) {
                $row = (array) $row;
                foreach (array_merge($drop, ['created_at', 'updated_at']) as $column) {
                    unset($row[$column]);
                }
                foreach ($row as $column => $value) {
                    if ($value === null) {
                        continue;
                    }
                    if (in_array($column, $dateColumns, true)) {
                        $row[$column] = substr((string) $value, 0, 10);
                    } elseif (in_array($column, ['linked_at', 'published_at', 'last_login_at', 'starts_at', 'ends_at', 'last_synced_at'], true)) {
                        $row[$column] = substr((string) $value, 0, 19);
                    } elseif (is_bool($value) || $column === 'is_system' || str_starts_with($column, 'is_') || $column === 'include_descendants' || $column === 'primary_membership_required') {
                        $row[$column] = (bool) $value;
                    } else {
                        $row[$column] = (string) $value;
                    }
                }
                $rows[] = $row;
            }
            $result[$table] = $rows;
        }

        return $result;
    }

    /**
     * created_at を元の値と比べられる行(導出イベントが行の created_at で置かれるもの)。
     * 作成を補うイベントは、置く位置に関わらず行の created_at で置かれる(論点19 決定1)ため、全行を比べる。
     *
     * @return array<string, array<string, string>>
     */
    private function createdAtSnapshot(): array
    {
        $key = [
            'group_types' => fn ($r) => (string) $r->id,
            'users' => fn ($r) => (string) $r->id,
            'groups' => fn ($r) => (string) $r->code,
            'roles' => fn ($r) => (string) $r->id,
            'role_features' => fn ($r) => $r->role_id.'/'.$r->feature_id,
            'role_assignments' => fn ($r) => (string) $r->id,
            'group_feature_assignments' => fn ($r) => $r->group_id.'/'.$r->feature_id,
            'memberships' => fn ($r) => $r->user_id.'/'.$r->group_id,
            'external_identities' => fn ($r) => $r->provider.'/'.$r->external_subject_id,
            'field_authorities' => fn ($r) => (string) $r->field_key,
            'company_calendar_years' => fn ($r) => (string) $r->id,
            'company_calendar_days' => fn ($r) => substr((string) $r->date, 0, 10),
        ];
        $except = [];

        $result = [];
        foreach ($key as $table => $keyOf) {
            foreach (DB::table($table)->get() as $row) {
                $k = $keyOf($row);
                if (in_array($k, $except[$table] ?? [], true)) {
                    continue;
                }
                $result[$table][$k] = substr((string) $row->created_at, 0, 19);
            }
            ksort($result[$table]);
        }

        return $result;
    }
}
