<?php

namespace Tests\Feature\EventSourcing;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Rebuild\EventStoreRebuildWriter;
use App\Domain\EventSourcing\Rebuild\PaidLeaveStreamRekeyer;
use App\Domain\EventSourcing\Rebuild\ReadModelTableLister;
use App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate;
use App\Domain\PaidLeaveAccount\Commands\ConfirmPaidLeaveUsage;
use App\Domain\PaidLeaveAccount\Commands\DesignatePaidLeaveUsage;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 有給口座・付与予定のストリーム付け替え(leave:rekey-paid-leave-streams)のシナリオテスト
 * (docs/changesets/20261021-paid-leave-aggregate-stream-id 論点3)。
 *
 * 入力は本番の形: 「旧い形の行 → 作り直し(--apply・--swap)→ 新しいコードで手動付与・消化」の後の stored_events。
 * 作り直しは最後の工程で同じ付け替えをかけるため、本番の形(付け替え前)は、作り直し後の行のうち社員 A の有給口座・付与予定の行を
 * 旧い形(aggregate_uuid=社員ID・userId無し)へ戻して再現する。基準Aは、戻す前の(新しい形の)履歴を全ReadModelを空にして再生した指紋。
 */
class PaidLeaveStreamRekeyScenarioTest extends TestCase
{
    use RefreshDatabase;

    private const AT = '2026-09-01 10:00:00';

    private const SCHEDULE_TABLES = ['paid_leave_schedule_entries', 'paid_leave_schedule_assessments'];

    private int $nextId = 1;

    /** @var array<string, int> */
    private array $versions = [];

    private User $userA;

    private User $userB;

    /** @var array<string, string> 基準Aの指紋(テーブル => 件数\tハッシュ) */
    private array $fingerprintA = [];

    /** @var array<string, mixed> 付け替え前の A の有給の状態 */
    private array $balanceA = [];

    public function test_rekey_apply_verify_swap_on_a_production_shaped_history(): void
    {
        $this->buildProductionShapedHistory();
        $before = $this->storedEventsById();
        $oldAccountRows = $this->accountRowsOf($this->userA);
        $this->assertNotEmpty($oldAccountRows);
        $accountStream = PaidLeaveAccountAggregate::streamIdFor($this->userA->id);

        // 試し実行: 何も書かず、件数と事前条件の結果を出す。
        $this->artisan('leave:rekey-paid-leave-streams')
            ->expectsOutputToContain('付け替える有給口座の行: '.count($oldAccountRows))
            ->expectsOutputToContain('取り除く付与予定の行: 1 件')
            ->expectsOutputToContain('事前条件: 満たしている')
            ->assertExitCode(0);
        $this->assertFalse(Schema::hasTable(EventStoreRebuildWriter::TABLE));
        $this->assertEquals($before, $this->storedEventsById());

        $this->artisan('leave:rekey-paid-leave-streams', ['--apply' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable(EventStoreRebuildWriter::TABLE));
        $this->artisan('leave:rekey-paid-leave-streams', ['--verify' => true])->assertExitCode(0);
        $this->assertGreaterThan(0, DB::table('users')->count(), 'verify の(a)が空振りしないよう、社員の行がある前提');
        $this->artisan('leave:rekey-paid-leave-streams', ['--swap' => true])->assertExitCode(0);

        $after = $this->storedEventsById();

        // 有給口座の行は新しいIDに付け替わり、userId を持つ。版は新しいストリームで1から連番。meta も合っている。
        $accountRows = DB::table('stored_events')->where('aggregate_uuid', $accountStream)->orderBy('id')->get();
        $this->assertCount(count($oldAccountRows), $accountRows);
        $this->assertSame(range(1, $accountRows->count()), $accountRows->pluck('aggregate_version')->map(fn ($v) => (int) $v)->all());
        foreach ($accountRows as $row) {
            $payload = json_decode($row->event_properties, true);
            $meta = json_decode($row->meta_data, true);
            $this->assertSame($this->userA->id, $payload['userId']);
            $this->assertSame($accountStream, $meta['aggregate-root-uuid']);
            $this->assertSame((int) $row->aggregate_version, $meta['aggregate-root-version']);
        }
        $this->assertSame(0, DB::table('stored_events')->where('aggregate_uuid', $this->userA->id)->where('event_class', 'like', 'paid_leave_account.%')->count());

        // 付与予定の行は無い。社員のストリームは user.* だけで、版は詰まっている。
        $this->assertSame(0, DB::table('stored_events')->where('event_class', 'like', 'paid_leave_schedule.%')->count());
        $userRows = DB::table('stored_events')->where('aggregate_uuid', $this->userA->id)->orderBy('id')->get();
        $this->assertNotEmpty($userRows);
        $this->assertSame(range(1, $userRows->count()), $userRows->pluck('aggregate_version')->map(fn ($v) => (int) $v)->all());
        foreach ($userRows as $row) {
            $this->assertStringStartsWith('user.', $row->event_class);
            $this->assertSame((int) $row->aggregate_version, json_decode($row->meta_data, true)['aggregate-root-version']);
        }

        // 他の行(有給口座・付与予定・社員のストリーム以外)は id・版・payload・meta・created_at が1つも変わらない。残る行の id も変わらない。
        $isMoved = fn (object $row) => str_starts_with($row->event_class, 'paid_leave_account.') || str_starts_with($row->event_class, 'paid_leave_schedule.')
            || in_array($row->aggregate_uuid, [$this->userA->id, $this->userB->id], true);
        $unchanged = 0;
        foreach ($before as $id => $row) {
            if ($isMoved((object) $row)) {
                continue;
            }
            $this->assertEquals($row, $after[$id] ?? null, "id={$id} {$row['event_class']}");
            $unchanged++;
        }
        $this->assertGreaterThan(0, $unchanged);
        // 社員Bの有給口座(新しいコードで書かれた新IDの行)は変わらない。
        foreach ($before as $id => $row) {
            if ($row['aggregate_uuid'] === PaidLeaveAccountAggregate::streamIdFor($this->userB->id)) {
                $this->assertEquals($row, $after[$id]);
            }
        }

        // 2回かけても同じ: 変換0件。
        $again = (new PaidLeaveStreamRekeyer)->rekey((new EventStoreRebuildWriter)->readRowArrays());
        $this->assertTrue($again->applied);
        $this->assertSame(0, $again->rekeyedRows);
        $this->assertSame(0, $again->removedRows());
        $this->assertSame(0, $again->renumberedRows);
        $this->artisan('leave:rekey-paid-leave-streams')
            ->expectsOutputToContain('付け替える有給口座の行: 0 件')
            ->expectsOutputToContain('取り除く付与予定の行: 0 件')
            ->assertExitCode(0);

        // 原則2: 付け替え後に全ReadModelを空にして再生した指紋が、基準Aと(付与予定の2表を除いて)一致する。
        $this->replayAllReadModels();
        $fingerprintB = $this->fingerprint();
        $this->assertSame([], array_keys(array_diff_assoc(
            array_diff_key($this->fingerprintA, array_flip(self::SCHEDULE_TABLES)),
            array_diff_key($fingerprintB, array_flip(self::SCHEDULE_TABLES)),
        )));
        $this->assertSame(array_keys($this->fingerprintA), array_keys($fingerprintB));
        // 付与予定の2表: 基準Aには行があり、付け替え後は空。
        $this->assertGreaterThan(0, (int) explode("\t", $this->fingerprintA['paid_leave_schedule_entries'])[0]);
        $this->assertSame(0, DB::table('paid_leave_schedule_entries')->count());
        $this->assertSame(0, DB::table('paid_leave_schedule_assessments')->count());
        // 手動付与の社員の残高・付与の一覧が前後で同じ。
        $this->assertEquals($this->balanceA, $this->paidLeaveStateOf($this->userA));
        $this->assertGreaterThan(0, count($this->balanceA['grants']));
    }

    public function test_preconditions_stop_the_rekey_when_the_schedule_was_used(): void
    {
        $user = User::factory()->create();
        $this->insertRaw((string) Str::uuid(), 'paid_leave_schedule.entry_granted', ['scheduleEntryId' => 'e1', 'grantId' => 'g1', 'operatorUserId' => $user->id]);
        $before = DB::table('stored_events')->count();

        $this->artisan('leave:rekey-paid-leave-streams')
            ->expectsOutputToContain('paid_leave_schedule.entry_granted')
            ->assertExitCode(1);
        $this->artisan('leave:rekey-paid-leave-streams', ['--apply' => true])->assertExitCode(1);
        $this->assertFalse(Schema::hasTable(EventStoreRebuildWriter::TABLE));
        $this->assertSame($before, DB::table('stored_events')->count());

        $violations = (new PaidLeaveStreamRekeyer)->violations((new EventStoreRebuildWriter)->readRowArrays());
        $this->assertSame(1, array_sum($violations));
    }

    public function test_assessment_override_and_manual_edit_stop_the_rekey(): void
    {
        $this->insertRaw((string) Str::uuid(), 'paid_leave_schedule.assessment_overridden', ['scheduleEntryId' => 'e1']);
        $this->insertRaw((string) Str::uuid(), 'paid_leave_schedule.entry_manually_edited', ['scheduleEntryId' => 'e1']);

        $this->artisan('leave:rekey-paid-leave-streams')->assertExitCode(1);
    }

    public function test_scheduled_batch_grant_stops_the_rekey_but_manual_and_carried_over_grants_do_not(): void
    {
        $user = User::factory()->create();
        $grant = fn (string $source) => ['grantId' => (string) Str::uuid(), 'grantedOn' => '2026-04-01', 'expiresOn' => '2027-03-31', 'grantedDays' => 10.0, 'grantReason' => null, 'source' => $source];

        $this->insertRaw($user->id, 'paid_leave_account.grant_created', $grant('manual'));
        $this->insertRaw($user->id, 'paid_leave_account.grant_created', $grant('carried_over'));
        $this->artisan('leave:rekey-paid-leave-streams')
            ->expectsOutputToContain('付け替える有給口座の行: 2 件')
            ->expectsOutputToContain('事前条件: 満たしている')
            ->assertExitCode(0);

        $this->insertRaw($user->id, 'paid_leave_account.grant_created', $grant('scheduled_batch'));
        $this->artisan('leave:rekey-paid-leave-streams')
            ->expectsOutputToContain("source='scheduled_batch'")
            ->assertExitCode(1);
        $this->artisan('leave:rekey-paid-leave-streams', ['--apply' => true])->assertExitCode(1);
        $this->assertFalse(Schema::hasTable(EventStoreRebuildWriter::TABLE));
    }

    public function test_swap_is_refused_when_the_verify_fails(): void
    {
        $user = User::factory()->create();
        $this->insertRaw($user->id, 'paid_leave_account.grant_created', ['grantId' => (string) Str::uuid(), 'grantedOn' => '2026-04-01', 'expiresOn' => '2027-03-31', 'grantedDays' => 10.0, 'grantReason' => null, 'source' => 'manual']);
        $this->artisan('leave:rekey-paid-leave-streams', ['--apply' => true])->assertExitCode(0);

        // 付け替え後の表を壊す(付与予定の行が残っている・他の行が変わっている)と、検査が失敗して入れ替えない。
        DB::table(EventStoreRebuildWriter::TABLE)->insert([
            'id' => 900, 'aggregate_uuid' => (string) Str::uuid(), 'aggregate_version' => 1, 'event_version' => 1,
            'event_class' => 'paid_leave_schedule.entry_cancelled', 'event_properties' => '{}', 'meta_data' => '{}', 'created_at' => self::AT,
        ]);

        $this->artisan('leave:rekey-paid-leave-streams', ['--verify' => true])->assertExitCode(1);
        $this->artisan('leave:rekey-paid-leave-streams', ['--swap' => true])->assertExitCode(1);
        $this->assertSame(1, DB::table('stored_events')->where('event_class', 'paid_leave_account.grant_created')->whereNull('event_properties->userId')->count());
    }

    private function buildProductionShapedHistory(): void
    {
        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();
        $approver = User::factory()->create();
        $grantId = (string) Str::uuid();
        $requestId = (string) Str::uuid();

        // 旧い形の行(社員 A の有給: 手動付与1件と消化1件)。
        $this->insertLegacy($grantId, 'paid_leave.granted', ['userId' => $this->userA->id, 'grantedOn' => '2026-04-01', 'expiresOn' => '2027-03-31', 'grantedDays' => 10.0, 'grantReason' => null]);
        $this->insertLegacy($requestId, 'paid_leave.requested', ['userId' => $this->userA->id, 'targetDate' => '2026-08-10', 'leaveType' => 'full', 'hours' => null, 'requestedDays' => 1.0, 'approverUserId' => $approver->id, 'reason' => null, 'requestGroupId' => null]);
        $this->insertLegacy($requestId, 'paid_leave.request_approved', ['approvedByUserId' => $approver->id]);
        $this->insertLegacy($grantId, 'paid_leave.used', ['userId' => $this->userA->id, 'paidLeaveRequestId' => $requestId, 'attendanceDayId' => (string) Str::uuid(), 'usedOn' => '2026-08-10', 'usedDays' => 1.0, 'usedMinutes' => null, 'usageType' => 'full']);

        $this->artisan('leave:rebuild-event-store')->assertExitCode(0);
        $this->artisan('leave:rebuild-event-store', ['--apply' => true])->assertExitCode(0);
        $this->artisan('leave:rebuild-event-store', ['--swap' => true])->assertExitCode(0);

        // 新しいコードで、別の社員 B の手動付与・消化を少し行う(新しいIDで userId つきで記録される)。
        $bus = app(CommandBus::class);
        $bus->dispatch(new GrantPaidLeave($this->userB->id, '2026-04-01', '2027-03-31', 10.0, null));
        $usageId = $bus->dispatch(new DesignatePaidLeaveUsage(
            userId: $this->userB->id, workflowRequestId: null, attendanceDayId: null, usedOn: '2026-09-10', usedDays: 1.0,
            usageType: 'full', paidLeaveRequestId: (string) Str::uuid(), approverUserId: $approver->id,
        ));
        $bus->dispatch(new ConfirmPaidLeaveUsage($this->userB->id, $usageId, $approver->id));

        // 社員 A の付与予定(新しい形で記録。自動付与の機能の名残)。
        $this->insertRaw(PaidLeaveScheduleAggregate::streamIdFor($this->userA->id), 'paid_leave_schedule.entry_created', [
            'userId' => $this->userA->id, 'scheduleEntryId' => (string) Str::uuid(), 'scheduledOn' => '2027-04-01', 'category' => 'standard', 'candidateGrantDays' => 11.0, 'isDeterminate' => true,
        ]);

        // 基準A: 付け替え前(新しい形)の履歴を、全ReadModelを空にして再生した指紋と、A の有給の状態。
        $this->replayAllReadModels();
        $this->fingerprintA = $this->fingerprint();
        $this->balanceA = $this->paidLeaveStateOf($this->userA);
        $this->assertGreaterThan(0, (int) DB::table('paid_leave_schedule_entries')->count());

        $this->revertToOldShape($this->userA);
    }

    /** 社員の有給口座・付与予定の行を、付け替え前の形(aggregate_uuid=社員ID・userId無し)へ戻し、その社員のストリームの版を id順に振り直す。 */
    private function revertToOldShape(User $user): void
    {
        $streams = [PaidLeaveAccountAggregate::streamIdFor($user->id), PaidLeaveScheduleAggregate::streamIdFor($user->id)];
        foreach (DB::table('stored_events')->whereIn('aggregate_uuid', $streams)->get() as $row) {
            $payload = json_decode($row->event_properties, true);
            unset($payload['userId']);
            DB::table('stored_events')->where('id', $row->id)->update([
                'aggregate_uuid' => $user->id,
                'aggregate_version' => 100000 + (int) $row->id, // 版の一意制約を避けるための仮の値(下で振り直す)
                'event_properties' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
        }
        $version = 0;
        foreach (DB::table('stored_events')->where('aggregate_uuid', $user->id)->orderBy('id')->get() as $row) {
            $version++;
            $meta = json_decode($row->meta_data, true);
            $meta['aggregate-root-uuid'] = $user->id;
            $meta['aggregate-root-version'] = $version;
            DB::table('stored_events')->where('id', $row->id)->update(['aggregate_version' => $version, 'meta_data' => json_encode($meta)]);
        }
    }

    /** @return list<object> */
    private function accountRowsOf(User $user): array
    {
        return DB::table('stored_events')->where('aggregate_uuid', $user->id)->where('event_class', 'like', 'paid_leave_account.%')->orderBy('id')->get()->all();
    }

    /** @return array<int, array<string, mixed>> id => 行(JSONは正規化して比較できるよう配列に展開) */
    private function storedEventsById(): array
    {
        $rows = [];
        foreach (DB::table('stored_events')->orderBy('id')->get() as $row) {
            $rows[(int) $row->id] = [
                'aggregate_uuid' => $row->aggregate_uuid, 'aggregate_version' => (int) $row->aggregate_version, 'event_class' => $row->event_class,
                'event_properties' => json_decode($row->event_properties, true), 'meta_data' => json_decode($row->meta_data, true), 'created_at' => (string) $row->created_at,
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> 社員の残高と付与の一覧(日時列を除く) */
    private function paidLeaveStateOf(User $user): array
    {
        $balance = DB::table('paid_leave_balances')->where('user_id', $user->id)->first();

        return [
            'balance' => $balance === null ? null : array_diff_key((array) $balance, array_flip(['created_at', 'updated_at'])),
            'grants' => DB::table('paid_leave_grants')->where('user_id', $user->id)->orderBy('id')->get()
                ->map(fn ($g) => array_diff_key((array) $g, array_flip(['created_at', 'updated_at', 'stored_event_id'])))->all(),
        ];
    }

    /** 全ReadModelを空にして、全Projectorをイベントから再生する。 */
    private function replayAllReadModels(): void
    {
        DB::statement('PRAGMA defer_foreign_keys = ON'); // テストのトランザクション内でも外部キーの検査を後ろへ回す(全表を順不同で空にするため)
        foreach (array_keys((new ReadModelTableLister)->tables(app_path('Domain'))) as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
        Artisan::call('event-sourcing:replay', ['--force' => true]);
    }

    /** @return array<string, string> テーブル => 件数\tハッシュ(既存の --readmodel-fingerprint の仕組み) */
    private function fingerprint(): array
    {
        Artisan::call('leave:rebuild-event-store', ['--readmodel-fingerprint' => true]);
        $result = [];
        foreach (array_filter(explode("\n", Artisan::output())) as $line) {
            [$table, $count, $hash] = explode("\t", $line);
            $result[$table] = "{$count}\t{$hash}";
        }

        return $result;
    }

    /** @param  array<string, mixed>  $payload */
    private function insertLegacy(string $aggregate, string $class, array $payload): void
    {
        $this->insertRaw($aggregate, $class, $payload);
    }

    /** 次の id・版で行を直接入れる(meta・created_at つき)。 */
    private function insertRaw(string $aggregate, string $class, array $payload): void
    {
        $this->nextId = max($this->nextId, (int) DB::table('stored_events')->max('id') + 1);
        $this->versions[$aggregate] = (int) DB::table('stored_events')->where('aggregate_uuid', $aggregate)->max('aggregate_version') + 1;
        DB::table('stored_events')->insert([
            'id' => $this->nextId,
            'aggregate_uuid' => $aggregate,
            'aggregate_version' => $this->versions[$aggregate],
            'event_version' => 1,
            'event_class' => $class,
            'event_properties' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'meta_data' => json_encode([
                'aggregate-root-uuid' => $aggregate,
                'aggregate-root-version' => $this->versions[$aggregate],
                'stored-event-id' => $this->nextId,
                'created-at' => self::AT,
            ]),
            'created_at' => self::AT,
        ]);
        $this->nextId++;
    }
}
