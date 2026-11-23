<?php

namespace App\Domain\EventSourcing\Rebuild;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * 旧項目名で記録された stored_events を新しい項目名へ書き換え、ReadModelを作り直して検証する
 * (docs/changesets/20261123-boolean-column-naming/spec.md 論点5・7。変換表は`BooleanPropertyConverter::RULES`)。
 *
 * - 試し実行(既定): 対象の event_class ごとの件数・補う件数・旧→新の例を数えるだけ。何も書かない。
 * - 本実行: トランザクションの前にバックアップ表を作る → 1つのトランザクションで「書き換え(id順・心拍つき) → 旧キー0件の確認 →
 *   ReadModelRebuilder::rebuild → changed_tables == 0 の確認」。失敗したら全て戻る(バックアップ表は残る)。
 *   トランザクションの中ではDDL・TRUNCATEを発行しない(MySQLの暗黙コミットで1トランザクションが崩れるため)。
 *
 * 書き換えるのは event_properties だけ。id・集約ID・aggregate_version・created_at・meta_data は変えない。補正ログ(stored_event_corrections)には書かない。
 * ReadModelの列名の改名は先に migration で済んでいる前提なので、ReadModelRebuilder の比較(同名の列どうし)をそのまま使う。
 */
final class BooleanPropertyMigrator
{
    public const BACKUP_PREFIX = 'stored_events_backup_';

    private const CHUNK = 500;

    private ?string $backupTable = null;

    public function __construct(
        private readonly BooleanPropertyConverter $converter,
        private readonly LegacyBooleanPropertyDetector $detector,
        private readonly EventStoreRebuildWriter $writer,
        private readonly ReadModelRebuilder $rebuilder,
    ) {}

    /** 直近の migrate() で作ったバックアップ表の名前(作っていなければnull。失敗後の案内に使う)。 */
    public function backupTable(): ?string
    {
        return $this->backupTable;
    }

    /**
     * @param  bool  $dryRun  true なら数えるだけ(何も書かない)
     * @param  float  $startedAt  時間の上限の起点(`microtime(true)`)
     * @return array<string, mixed> 要約。status は nothing(対象なし)か done
     */
    public function migrate(bool $dryRun, float $startedAt): array
    {
        $this->backupTable = null;

        if (Schema::hasTable('snapshots') && DB::table('snapshots')->exists()) {
            throw new RuntimeException('snapshots テーブルに行があります(集約のスナップショットは書き換え後の履歴と一致しないため、削除してから実行してください)。');
        }

        $scan = $this->scan();
        ReadModelRebuilder::heartbeat();
        if ($scan['unfillable'] !== [] && ! $dryRun) {
            throw new RuntimeException($this->unfillableMessage($scan['unfillable']));
        }
        $summary = [
            'status' => $scan['target_count'] === 0 && $scan['unfillable'] === [] ? 'nothing' : 'done',
            'unfillable' => $scan['unfillable'],
            'dry_run' => $dryRun,
            'target_count' => $scan['target_count'],
            'filled_count' => $scan['filled_count'],
            'events' => $scan['events'],
            'backup_table' => null,
        ];
        if ($scan['target_count'] === 0 || $dryRun) {
            return $summary;
        }

        $this->createBackup();
        $summary['backup_table'] = $this->backupTable;
        ReadModelRebuilder::heartbeat();

        $limitSeconds = (int) config('read_model_rebuild.time_limit_minutes', 180) * 60;
        // SQLiteではトランザクションの中で外部キーの検査を切れないため、先に切る(ReadModelRebuilder と同じ)。
        Schema::disableForeignKeyConstraints();
        $level = DB::transactionLevel();
        try {
            DB::beginTransaction();

            $this->assertMatchesBackup();
            $updated = $this->rewrite($startedAt, $limitSeconds);
            if ($updated !== $scan['target_count']) {
                throw new RuntimeException("書き換えた行数({$updated})が対象の件数({$scan['target_count']})と一致しません。");
            }

            $remaining = $this->detector->count();
            if ($remaining !== 0) {
                throw new RuntimeException("書き換え後も旧キーを持つ行が{$remaining}件残っています。");
            }
            ReadModelRebuilder::heartbeat();
            $this->rebuilder->assertWithinTimeLimit($startedAt, $limitSeconds);

            $readModels = $this->rebuilder->rebuild(false, $startedAt);
            $summary['tables'] = $readModels['tables'];
            $summary['changed_tables'] = $readModels['changed_tables'];
            if ($readModels['changed_tables'] !== 0) {
                $changed = array_column(array_filter($readModels['tables'], fn (array $row) => $row['changed']), 'table');
                throw new RuntimeException('ReadModelの作り直しの結果が書き換え前と一致しません(変更のあったテーブル: '.implode(', ', $changed).')。');
            }

            DB::commit();
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

    /**
     * 対象の行を数える(読み取りのみ)。旧キーと新キーが両方ある行があれば例外。
     *
     * @return array{unfillable: list<array{id: int, event_class: string, reason: string}>, target_count: int, filled_count: int, events: array<string, array{target: int, filled: int, example: array{id: int, changes: list<string>}|null, filled_example: array{id: int, changes: list<string>}|null}>}
     */
    private function scan(): array
    {
        $events = [];
        $target = 0;
        $filled = 0;
        $conflicts = [];
        $unfillable = [];
        DB::table('stored_events')
            ->whereIn('event_class', BooleanPropertyConverter::eventClasses())
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($chunk) use (&$events, &$target, &$filled, &$conflicts, &$unfillable): void {
                foreach ($chunk as $row) {
                    $class = (string) $row->event_class;
                    $conversion = $this->converter->convert($class, (string) $row->event_properties);
                    if ($conversion->conflict !== null) {
                        $conflicts[] = "id={$row->id} {$class}: {$conversion->conflict}";

                        continue;
                    }
                    if ($conversion->unfillable !== null) {
                        $unfillable[] = ['id' => (int) $row->id, 'event_class' => $class, 'reason' => $conversion->unfillable];

                        continue;
                    }
                    if ($conversion->newJson === null) {
                        continue;
                    }
                    $events[$class] ??= ['target' => 0, 'filled' => 0, 'example' => null, 'filled_example' => null];
                    $events[$class]['target']++;
                    $target++;
                    $events[$class]['example'] ??= ['id' => (int) $row->id, 'changes' => $conversion->changes];
                    if ($conversion->filled) {
                        $events[$class]['filled']++;
                        $filled++;
                        $events[$class]['filled_example'] ??= ['id' => (int) $row->id, 'changes' => $conversion->changes];
                    }
                }
                ReadModelRebuilder::heartbeat();
            });

        if ($conflicts !== []) {
            throw new RuntimeException('旧キーと新キーが両方ある行があります(原因を確かめてから実行してください): '.implode(' / ', array_slice($conflicts, 0, 10)).(count($conflicts) > 10 ? ' ほか'.(count($conflicts) - 10).'件' : ''));
        }
        ksort($events);

        return ['unfillable' => $unfillable, 'target_count' => $target, 'filled_count' => $filled, 'events' => $events];
    }

    /** @param  list<array{id: int, event_class: string, reason: string}>  $unfillable */
    private function unfillableMessage(array $unfillable): string
    {
        $items = array_map(fn (array $row) => "id={$row['id']} {$row['event_class']}: {$row['reason']}", array_slice($unfillable, 0, 10));

        return '補えない行が'.count($unfillable).'件あります(過去の事実が決められないため、人が確認してから実行してください): '
            .implode(' / ', $items).(count($unfillable) > 10 ? ' ほか'.(count($unfillable) - 10).'件' : '');
    }

    /** トランザクションの中で、対象の行の event_properties だけを書き換える。書き換えた行数を返す。 */
    private function rewrite(float $startedAt, int $limitSeconds): int
    {
        $updated = 0;
        DB::table('stored_events')
            ->whereIn('event_class', BooleanPropertyConverter::eventClasses())
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($chunk) use (&$updated, $startedAt, $limitSeconds): void {
                foreach ($chunk as $row) {
                    $conversion = $this->converter->convert((string) $row->event_class, (string) $row->event_properties);
                    if ($conversion->conflict !== null || $conversion->unfillable !== null) {
                        throw new RuntimeException("id={$row->id}: ".($conversion->conflict ?? $conversion->unfillable));
                    }
                    if ($conversion->newJson === null) {
                        continue;
                    }
                    DB::table('stored_events')->where('id', $row->id)->update(['event_properties' => $conversion->newJson]);
                    $updated++;
                }
                ReadModelRebuilder::heartbeat();
                $this->rebuilder->assertWithinTimeLimit($startedAt, $limitSeconds);
            });

        return $updated;
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

    private function assertMatchesBackup(): void
    {
        $backup = (string) $this->backupTable;
        $count = (int) DB::table('stored_events')->count();
        $maxId = (int) DB::table('stored_events')->max('id');
        $backupCount = (int) DB::table($backup)->count();
        $backupMax = (int) DB::table($backup)->max('id');
        if ($backupCount !== $count || $backupMax !== $maxId) {
            throw new RuntimeException("バックアップ表 {$backup} と stored_events の件数・最大idが一致しません(バックアップ {$backupCount} 件・最大id {$backupMax}、stored_events {$count} 件・最大id {$maxId})。バックアップ後に書き込みがあった可能性があります。");
        }
    }
}
