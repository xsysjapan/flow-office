<?php

namespace App\Domain\EventSourcing\Rebuild;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * 旧形式の backoffice_task.assigned の移行(stored_events の作り直し)と ReadModel の作り直しを、1つのDBトランザクションで行う
 * (docs/changesets/20261122-backoffice-assignment-migration-admin/spec.md 論点4)。
 *
 * 手順: 旧形式の件数が0なら何もしない → (本実行のみ)トランザクションの前にバックアップ表を作る → トランザクション開始 →
 * 読み込み・変換 → (本実行のみ)バックアップとの件数・最大id照合 → stored_events を全削除して変換後の行を入れる →
 * 補正ログの stored_event_id・aggregate_version を新しい値へ付け替える → 検証 → ReadModelRebuilder::rebuild(外側のトランザクション内) →
 * 本実行はコミット・試し実行はロールバック(例外ならロールバックして再送出)。
 * トランザクションの中では CREATE/ALTER/DROP/RENAME/TRUNCATE を発行しない(MySQLの暗黙コミットで1トランザクションが崩れるため)。
 */
final class BackOfficeAssignmentMigrator
{
    public const BACKUP_PREFIX = 'stored_events_before_rebuild_';

    private const CHUNK = 500;

    private ?string $backupTable = null;

    public function __construct(
        private readonly LegacyBackOfficeAssignmentDetector $detector,
        private readonly BackOfficeTaskAssignmentSplitter $splitter,
        private readonly BackOfficeTaskAssignmentRebuildVerifier $verifier,
        private readonly EventStoreRebuildWriter $writer,
        private readonly ReadModelRebuilder $rebuilder,
    ) {}

    /** 直近の migrate() で作ったバックアップ表の名前(作っていなければnull。失敗後の案内に使う)。 */
    public function backupTable(): ?string
    {
        return $this->backupTable;
    }

    /**
     * @param  float  $startedAt  時間の上限の起点(`microtime(true)`)
     * @return array<string, mixed> 要約。status は nothing(対象なし)か done
     */
    public function migrate(bool $dryRun, float $startedAt): array
    {
        $this->backupTable = null;

        $legacyCount = $this->detector->count();
        if ($legacyCount === 0) {
            return ['status' => 'nothing', 'dry_run' => $dryRun, 'target_count' => 0];
        }
        ReadModelRebuilder::heartbeat();

        if (! $dryRun) {
            $this->createBackup();
            ReadModelRebuilder::heartbeat();
        }

        $limitSeconds = (int) config('read_model_rebuild.time_limit_minutes', 180) * 60;

        Schema::disableForeignKeyConstraints();
        $level = DB::transactionLevel();
        try {
            DB::beginTransaction();

            $before = $this->writer->readRowArrays();
            $result = $this->splitter->split($before);
            if ($result->violations !== []) {
                throw new RuntimeException('変換できない並びがあります: '.implode(' / ', $result->violations));
            }
            ReadModelRebuilder::heartbeat();
            if (! $dryRun) {
                $oldMaxId = array_reduce($before, fn (int $max, array $row) => max($max, (int) $row['id']), 0);
                $oldCount = count($before);
                $this->assertMatchesBackup($oldCount, $oldMaxId);
                // 検証の比較元は(本実行は)バックアップ表なので、変換前の行は手放す。
                $before = null;
            } else {
                $oldMaxId = array_reduce($before, fn (int $max, array $row) => max($max, (int) $row['id']), 0);
            }

            $rows = $result->rows;
            $idMap = $result->idMap;
            $versionMap = $result->versionMap;
            $summary = [
                'status' => 'done',
                'dry_run' => $dryRun,
                'target_count' => $result->targetCount,
                'inserted_count' => $result->insertedStatusChanged,
                'skipped_converted' => $result->skippedConverted,
                'previous_status_counts' => $result->previousStatusCounts,
                'terminal_aggregates' => $result->terminalTransitions,
                'backup_table' => $this->backupTable,
            ];
            $newMaxId = count($rows);
            unset($result);

            DB::table('stored_events')->delete();
            $this->insertRows($rows);
            unset($rows);
            ReadModelRebuilder::heartbeat();
            $this->rebuilder->assertWithinTimeLimit($startedAt, $limitSeconds);

            [$summary['corrections_relinked'], $summary['corrections_unmapped']] = $this->relinkCorrections($idMap, $versionMap, max($oldMaxId, $newMaxId));
            unset($idMap, $versionMap);
            ReadModelRebuilder::heartbeat();

            $problems = $this->verifier->verify('stored_events', $dryRun ? $before : (string) $this->backupTable);
            if ($problems !== []) {
                throw new RuntimeException('移行後の検証に失敗しました: '.implode(' / ', array_slice($problems, 0, 5)));
            }
            unset($before);
            ReadModelRebuilder::heartbeat();
            $this->rebuilder->assertWithinTimeLimit($startedAt, $limitSeconds);

            $readModels = $this->rebuilder->rebuild($dryRun, $startedAt);
            $summary['tables'] = $readModels['tables'];
            $summary['changed_tables'] = $readModels['changed_tables'];

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (Throwable $e) {
            while (DB::transactionLevel() > $level) {
                DB::rollBack();
            }
            throw $e;
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        return $summary;
    }

    /** トランザクションの前に、stored_events の複写(主キー・索引つき)を作る。既にあれば失敗する。 */
    private function createBackup(): void
    {
        $backup = self::BACKUP_PREFIX.now()->format('YmdHis');
        if (Schema::hasTable($backup)) {
            throw new RuntimeException("バックアップ表 {$backup} が既に存在します。");
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("CREATE TABLE `{$backup}` LIKE `stored_events`");
        } else {
            // SQLiteには LIKE が無いので、作り直し用の表と同じ構造で作る。
            $this->writer->write([], $backup);
        }
        $this->backupTable = $backup;

        $columns = 'id, aggregate_uuid, aggregate_version, event_version, event_class, event_properties, meta_data, created_at';
        DB::statement("INSERT INTO {$backup} ({$columns}) SELECT {$columns} FROM stored_events");
    }

    private function assertMatchesBackup(int $count, int $maxId): void
    {
        $backup = (string) $this->backupTable;
        $backupCount = (int) DB::table($backup)->count();
        $backupMax = (int) DB::table($backup)->max('id');
        if ($backupCount !== $count || $backupMax !== $maxId) {
            throw new RuntimeException("バックアップ表 {$backup} と stored_events の件数・最大idが一致しません(バックアップ {$backupCount} 件・最大id {$backupMax}、stored_events {$count} 件・最大id {$maxId})。バックアップ後に書き込みがあった可能性があります。");
        }
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function insertRows(array $rows): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('stored_events')->insert(array_map(fn (array $row) => [
                'id' => $row['id'],
                'aggregate_uuid' => $row['aggregate_uuid'],
                'aggregate_version' => $row['aggregate_version'],
                'event_version' => $row['event_version'],
                'event_class' => $row['event_class'],
                'event_properties' => json_encode($row['event_properties'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'meta_data' => json_encode($row['meta_data'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => $row['created_at'],
            ], $chunk));
            ReadModelRebuilder::heartbeat();
        }
    }

    /**
     * 補正ログの stored_event_id・aggregate_version を新しい値へ付け替える。unique(correction_key, stored_event_id) に途中で衝突しないよう、
     * 対応のある行をいったん全て大きな値へ逃がしてから、1行ずつ新しい値にする。対応の無い行(補正で削除されたイベントを指す行)は変えない。
     *
     * @param  array<int, int>  $idMap  旧id => 新id
     * @param  array<int, int>  $versionMap  旧id => 新しい版
     * @return array{0: int, 1: int} 付け替えた件数、対応が無く変えなかった件数
     */
    private function relinkCorrections(array $idMap, array $versionMap, int $maxStoredEventId): array
    {
        $table = EventStoreRebuildWriter::CORRECTION_LOG;
        if (! Schema::hasTable($table)) {
            return [0, 0];
        }

        $mapped = [];
        $unmapped = 0;
        $maxLogged = 0;
        foreach (DB::table($table)->orderBy('id')->get(['id', 'stored_event_id']) as $row) {
            $old = (int) $row->stored_event_id;
            $maxLogged = max($maxLogged, $old);
            if (isset($idMap[$old])) {
                $mapped[(int) $row->id] = $old;
            } else {
                $unmapped++;
            }
        }
        if ($mapped === []) {
            return [0, $unmapped];
        }

        $offset = max($maxStoredEventId, $maxLogged) + 1;
        foreach (array_chunk(array_keys($mapped), self::CHUNK) as $logIds) {
            DB::table($table)->whereIn('id', $logIds)->update(['stored_event_id' => DB::raw('stored_event_id + '.$offset)]);
        }
        foreach ($mapped as $logId => $old) {
            $update = ['stored_event_id' => $idMap[$old]];
            if (isset($versionMap[$old])) {
                $update['aggregate_version'] = $versionMap[$old];
            }
            DB::table($table)->where('id', $logId)->update($update);
        }

        return [count($mapped), $unmapped];
    }
}
