<?php

namespace Tests\Feature\EventSourcing;

use App\Domain\Attendance\Aggregates\CalendarBulkOperationAggregate;
use App\Domain\Attendance\Commands\ApplyCalendarBulkOperation;
use App\Domain\Attendance\Commands\CreateDefaultWorkStyle;
use App\Domain\Attendance\Commands\RevertCalendarBulkOperation;
use App\Domain\Attendance\Projectors\CalendarBulkOperationProjector;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Rebuild\CalendarBulkOperationTargetIdMigrator;
use App\Domain\EventSourcing\Rebuild\LegacyCalendarBulkOperationTargetIdDetector;
use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\EventSourcing\Concerns\BuildsReadModelRebuildFixtures;
use Tests\TestCase;

/**
 * 一括操作の対象行idの決定性と、過去の取消イベントの補正コマンド(events:fix-calendar-bulk-operation-target-ids)のシナリオテスト
 * (docs/changesets/20261123-rebuild-drift-fixes/spec.md 論点2・3)。
 *
 * 旧コード(対象行のidがランダム)の状態は、新しいコードで作った履歴のライブの対象行のidと取消イベントのidを、ランダムなidへ
 * 直接書き換えて再現する(テストの前提データ作り)。
 */
class CalendarBulkOperationTargetIdScenarioTest extends TestCase
{
    use BuildsReadModelRebuildFixtures;
    use DatabaseMigrations;

    private const COMMAND = 'events:fix-calendar-bulk-operation-target-ids';

    private const TARGETS = 'calendar_bulk_operation_targets';

    private User $admin;

    private User $worker;

    private string $workStyleId;

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

        $this->admin = $this->createEventAdmin();
        $this->worker = $this->createEventUser('Bulk Worker');
        $this->bus()->dispatch(new CreateDefaultWorkStyle([], $this->admin->id));
        $this->workStyleId = (string) DB::table('work_styles')->value('id');
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

    /** @param  list<string>  $dates */
    private function apply(array $dates, string $policy = 'skip_existing'): string
    {
        $entries = array_map(fn (string $date) => [
            'user_id' => $this->worker->id, 'work_date' => $date, 'work_style_id' => $this->workStyleId, 'schedule_state' => 'WORK',
        ], $dates);

        return $this->bus()->dispatch(new ApplyCalendarBulkOperation(
            'bulk_edit', ['entries' => $entries], $policy, 'テスト', $this->admin->id,
        ))->id;
    }

    private function revert(string $operationId): void
    {
        $this->bus()->dispatch(new RevertCalendarBulkOperation($operationId, $this->admin->id));
    }

    /** @return array<string, string> 対象行のid => 内容(idを除く)のJSON */
    private function liveTargets(): array
    {
        $rows = [];
        foreach (DB::table(self::TARGETS)->orderBy('id')->get() as $row) {
            $row = (array) $row;
            $id = $row['id'];
            unset($row['id'], $row['created_at']);
            $rows[$id] = json_encode($row);
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function storedEvents(): array
    {
        return DB::table('stored_events')->orderBy('id')->get()->keyBy('id')->map(fn ($row) => (array) $row)->all();
    }

    /** @return list<array<string, mixed>> */
    private function revertedPayloads(string $operationId): array
    {
        return DB::table('stored_events')
            ->where('event_class', 'calendar_bulk_operation.reverted')
            ->where('aggregate_uuid', $operationId)
            ->orderBy('id')->get()
            ->map(fn ($row) => json_decode($row->event_properties, true))
            ->all();
    }

    /**
     * 旧コードの状態にする: 操作の対象行のidをランダムなidへ書き換え、その操作の取消イベントのidも同じランダムなidにする。
     *
     * @return array<string, string> 新id => 旧id
     */
    private function makeLegacy(string $operationId): array
    {
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

        return $old;
    }

    /** 同じ取消イベントをもう1件、版を進めて追記する(旧コードで二重に取り消された履歴の再現)。 */
    private function duplicateRevertedEvent(string $operationId): void
    {
        $row = (array) DB::table('stored_events')->where('event_class', 'calendar_bulk_operation.reverted')->where('aggregate_uuid', $operationId)->orderBy('id')->first();
        unset($row['id']);
        $row['aggregate_version'] = (int) DB::table('stored_events')->where('aggregate_uuid', $operationId)->max('aggregate_version') + 1;
        DB::table('stored_events')->insert($row);
    }

    /** @return list<string> */
    private function backupTables(): array
    {
        return array_values(array_filter(
            array_column(Schema::getTables(), 'name'),
            fn (string $name) => str_starts_with($name, CalendarBulkOperationTargetIdMigrator::BACKUP_PREFIX),
        ));
    }

    private function correctionLogCount(): int
    {
        return DB::table('stored_event_corrections')->where('correction_key', CalendarBulkOperationTargetIdMigrator::CORRECTION_KEY)->count();
    }

    public function test_target_ids_are_deterministic_and_the_rebuild_keeps_them(): void
    {
        $operationId = $this->apply(['2026-12-01', '2026-12-02']);
        $this->revert($operationId);
        $other = $this->apply(['2026-12-03']);

        $ids = DB::table(self::TARGETS)->where('calendar_bulk_operation_id', $operationId)->orderBy('id')->pluck('id')->all();
        $expected = [CalendarBulkOperationProjector::targetId($operationId, 0), CalendarBulkOperationProjector::targetId($operationId, 1)];
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertSame(
            [CalendarBulkOperationProjector::targetId($other, 0)],
            DB::table(self::TARGETS)->where('calendar_bulk_operation_id', $other)->pluck('id')->all(),
        );
        $reverted = $this->revertedPayloads($operationId)[0]['revertedTargetIds'];
        $this->assertEqualsCanonicalizing($expected, $reverted);

        $before = $this->liveTargets();
        $dry = app(ReadModelRebuilder::class)->rebuild(true);
        $this->assertSame(0, $dry['changed_tables'], '一括操作の適用と取消の後に、試し実行の差分が0');

        app(ReadModelRebuilder::class)->rebuild(false);
        $this->assertSame($before, $this->liveTargets(), '作り直しの前後で対象行のidと内容が同じ');
        $this->assertSame(0, (new LegacyCalendarBulkOperationTargetIdDetector)->count());
    }

    public function test_dry_run_is_the_default_and_changes_nothing(): void
    {
        $operationId = $this->apply(['2026-12-01', '2026-12-02']);
        $this->revert($operationId);
        $old = $this->makeLegacy($operationId);
        $events = $this->storedEvents();
        $live = $this->liveTargets();
        $this->assertSame(1, (new LegacyCalendarBulkOperationTargetIdDetector)->count());

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('試し実行(データは変更していません')
            ->expectsOutputToContain(array_values($old)[0])
            ->assertExitCode(0);

        $this->assertSame($events, $this->storedEvents());
        $this->assertSame($live, $this->liveTargets());
        $this->assertSame([], $this->backupTables());
        $this->assertSame(0, $this->correctionLogCount());
    }

    public function test_execute_rewrites_the_reverted_events_and_rebuilds_with_the_new_ids(): void
    {
        $operationId = $this->apply(['2026-12-01', '2026-12-02']);
        $this->revert($operationId);
        $other = $this->apply(['2026-12-03']);
        $this->revert($other);
        $expectedContents = array_values($this->liveTargets());
        sort($expectedContents);
        $oldOfFirst = $this->makeLegacy($operationId);
        $this->makeLegacy($other);
        $beforeEvents = $this->storedEvents();
        $legacyPayload = $this->revertedPayloads($operationId)[0];
        $this->assertEqualsCanonicalizing(array_values($oldOfFirst), $legacyPayload['revertedTargetIds']);

        $this->artisan(self::COMMAND, ['--execute' => true])
            ->expectsOutputToContain('書き換えました')
            ->assertExitCode(0);
        $this->assertSame(0, DB::transactionLevel());

        // 取消イベントのidは新しいid、それ以外の項目・イベントの数・他の列は変わらない。
        $afterEvents = $this->storedEvents();
        $this->assertSame(array_keys($beforeEvents), array_keys($afterEvents));
        foreach ($afterEvents as $id => $row) {
            $this->assertSame(
                array_diff_key($beforeEvents[$id], ['event_properties' => 1]),
                array_diff_key($row, ['event_properties' => 1]),
            );
        }
        $payload = $this->revertedPayloads($operationId)[0];
        $this->assertEqualsCanonicalizing(
            [CalendarBulkOperationProjector::targetId($operationId, 0), CalendarBulkOperationProjector::targetId($operationId, 1)],
            $payload['revertedTargetIds'],
        );
        $this->assertSame($legacyPayload['revertedByUserId'], $payload['revertedByUserId']);
        $this->assertSame([], $payload['excludedTargetIds']);

        // 作り直した対象行は新しいidで、内容は補正前と同じ。取消イベントのidはすべて存在する。
        $contents = array_values($this->liveTargets());
        sort($contents);
        $this->assertSame($expectedContents, $contents);
        $liveIds = DB::table(self::TARGETS)->pluck('id')->all();
        foreach ($this->revertedPayloads($operationId)[0]['revertedTargetIds'] as $id) {
            $this->assertContains($id, $liveIds);
        }
        $this->assertSame(0, (new LegacyCalendarBulkOperationTargetIdDetector)->count());
        $this->assertSame(0, app(ReadModelRebuilder::class)->rebuild(true)['changed_tables']);
    }

    public function test_execute_writes_the_backup_table_and_the_correction_log(): void
    {
        $operationId = $this->apply(['2026-12-01']);
        $this->revert($operationId);
        $this->makeLegacy($operationId);
        $beforeRow = (array) DB::table('stored_events')->where('event_class', 'calendar_bulk_operation.reverted')->first();

        $this->artisan(self::COMMAND, ['--execute' => true])->assertExitCode(0);

        $backups = $this->backupTables();
        $this->assertCount(1, $backups);
        $backupRows = DB::table($backups[0])->get();
        $this->assertCount(1, $backupRows);
        $this->assertSame($beforeRow['id'], $backupRows[0]->id);
        $this->assertSame($beforeRow['event_properties'], $backupRows[0]->event_properties);

        $logs = DB::table('stored_event_corrections')->where('correction_key', CalendarBulkOperationTargetIdMigrator::CORRECTION_KEY)->get();
        $this->assertCount(1, $logs);
        $this->assertSame((int) $beforeRow['id'], (int) $logs[0]->stored_event_id);
        $this->assertSame('rewrite', $logs[0]->operation);
        $this->assertSame($beforeRow['event_properties'], $logs[0]->before_event_properties);
        $this->assertSame($backups[0], $logs[0]->backup_table);
        $this->assertSame(
            DB::table('stored_events')->where('id', $beforeRow['id'])->value('event_properties'),
            $logs[0]->after_event_properties,
        );
    }

    public function test_it_is_idempotent(): void
    {
        $operationId = $this->apply(['2026-12-01', '2026-12-02']);
        $this->revert($operationId);
        $this->makeLegacy($operationId);

        $this->artisan(self::COMMAND, ['--execute' => true])->assertExitCode(0);
        $events = $this->storedEvents();
        $live = DB::table(self::TARGETS)->orderBy('id')->pluck('id')->all();

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('対象なし')
            ->assertExitCode(0);
        $this->artisan(self::COMMAND, ['--execute' => true])
            ->expectsOutputToContain('対象なし')
            ->assertExitCode(0);

        $this->assertSame($events, $this->storedEvents());
        $this->assertSame($live, DB::table(self::TARGETS)->orderBy('id')->pluck('id')->all());
        $this->assertCount(1, $this->backupTables());
        $this->assertSame(1, $this->correctionLogCount());
    }

    public function test_it_stops_without_changing_anything_when_an_id_cannot_be_mapped(): void
    {
        $operationId = $this->apply(['2026-12-01', '2026-12-02']);
        $this->revert($operationId);
        $old = $this->makeLegacy($operationId);
        // ライブの対象行が1行無い(対応が付かないidができる)。
        $missing = array_values($old)[0];
        DB::table(self::TARGETS)->where('id', $missing)->delete();
        $events = $this->storedEvents();
        $live = $this->liveTargets();

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('対応が付かないid: 1 件')
            ->expectsOutputToContain($missing)
            ->assertExitCode(0);

        $this->artisan(self::COMMAND, ['--execute' => true])->assertExitCode(1);

        $this->assertSame($events, $this->storedEvents());
        $this->assertSame($live, $this->liveTargets());
        $this->assertSame([], $this->backupTables());
        $this->assertSame(0, $this->correctionLogCount());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_targets_with_the_same_key_are_paired_one_to_one(): void
    {
        // 適用イベントの要素のキー(利用者・日付・予定行のid・結果・error_code・previous_snapshot)が同じ2件(と、別の1件)を直接記録する。
        $operationId = (string) Str::uuid();
        $target = ['user_id' => $this->worker->id, 'work_date' => '2026-12-01', 'employee_calendar_entry_id' => null, 'result' => 'applied', 'error_code' => null, 'previous_snapshot' => ['b' => 1, 'a' => ['y' => 2, 'x' => 1]]];
        $this->insertEvent($operationId, 1, 'calendar_bulk_operation.applied', [
            'operationType' => 'bulk_edit', 'targetScope' => ['entries' => []], 'conflictPolicy' => 'overwrite', 'reason' => 'テスト',
            'targets' => [$target, $target, ['work_date' => '2026-12-02'] + $target], 'requestedByUserId' => $this->admin->id,
        ]);
        app(ReadModelRebuilder::class)->rebuild(false);
        $new = [0, 1, 2];
        $newIds = array_map(fn (int $i) => CalendarBulkOperationProjector::targetId($operationId, $i), $new);
        $this->assertEqualsCanonicalizing($newIds, DB::table(self::TARGETS)->pluck('id')->all());
        $this->insertEvent($operationId, 2, 'calendar_bulk_operation.reverted', [
            'revertedTargetIds' => $newIds, 'excludedTargetIds' => [], 'revertedByUserId' => $this->admin->id,
        ]);
        $old = $this->makeLegacy($operationId);
        $this->assertCount(3, $old);

        $this->artisan(self::COMMAND, ['--execute' => true])->assertExitCode(0);

        $this->assertEqualsCanonicalizing($newIds, $this->revertedPayloads($operationId)[0]['revertedTargetIds']);
        $this->assertEqualsCanonicalizing($newIds, DB::table(self::TARGETS)->pluck('id')->all());
        // 日付が違う1件は、必ず位置2の行に対応する。
        $this->assertSame('2026-12-02', substr((string) DB::table(self::TARGETS)->where('id', $newIds[2])->value('work_date'), 0, 10));
    }

    /** @param  array<string, mixed>  $properties */
    private function insertEvent(string $aggregateUuid, int $version, string $class, array $properties): void
    {
        DB::table('stored_events')->insert([
            'aggregate_uuid' => $aggregateUuid, 'aggregate_version' => $version, 'event_version' => 1, 'event_class' => $class,
            'event_properties' => json_encode($properties), 'meta_data' => json_encode(['aggregate-root-uuid' => $aggregateUuid]), 'created_at' => now(),
        ]);
    }

    public function test_double_reverted_events_are_all_rewritten_and_kept(): void
    {
        $operationId = $this->apply(['2026-12-01', '2026-12-02']);
        $this->revert($operationId);
        $this->duplicateRevertedEvent($operationId);
        $this->makeLegacy($operationId);
        $count = DB::table('stored_events')->count();
        $this->assertSame(2, (new \ReflectionClass(LegacyCalendarBulkOperationTargetIdDetector::class))->newInstance()->count());

        $this->artisan(self::COMMAND, ['--execute' => true])->assertExitCode(0);

        $this->assertSame($count, DB::table('stored_events')->count(), 'イベントは消さない');
        $payloads = $this->revertedPayloads($operationId);
        $this->assertCount(2, $payloads);
        $expected = [CalendarBulkOperationProjector::targetId($operationId, 0), CalendarBulkOperationProjector::targetId($operationId, 1)];
        foreach ($payloads as $payload) {
            $this->assertEqualsCanonicalizing($expected, $payload['revertedTargetIds']);
        }
        $this->assertSame(2, $this->correctionLogCount());
        // 二重取消の履歴を集約に再構成しても例外にならない。
        $this->assertInstanceOf(CalendarBulkOperationAggregate::class, CalendarBulkOperationAggregate::retrieve($operationId));
    }

    public function test_the_rebuild_is_refused_while_uncorrected_reverted_events_exist(): void
    {
        $operationId = $this->apply(['2026-12-01']);
        $this->revert($operationId);
        $this->makeLegacy($operationId);
        $live = $this->liveTargets();

        $this->artisan('read-models:rebuild', ['--dry-run' => true])
            ->expectsOutputToContain(self::COMMAND)
            ->assertExitCode(1);
        $this->artisan('read-models:rebuild')
            ->expectsOutputToContain(self::COMMAND)
            ->assertExitCode(1);
        $this->artisan('projections:rebuild')
            ->expectsOutputToContain(self::COMMAND)
            ->assertExitCode(1);
        $this->assertSame($live, $this->liveTargets(), '拒否したので対象行は消えていない');

        $this->artisan(self::COMMAND, ['--execute' => true])->assertExitCode(0);
        $this->artisan('read-models:rebuild', ['--dry-run' => true])->assertExitCode(0);
    }
}
