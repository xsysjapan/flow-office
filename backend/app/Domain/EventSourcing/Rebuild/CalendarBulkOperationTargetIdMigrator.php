<?php

namespace App\Domain\EventSourcing\Rebuild;

use App\Domain\EventSourcing\Correction\StoredEventCorrector;
use App\Domain\EventSourcing\Correction\StoredEventRewrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * 過去の取消イベント(calendar_bulk_operation.reverted)の`revertedTargetIds`・`excludedTargetIds`を、新しいidへ書き換え、
 * ReadModelを作り直して検証する(docs/changesets/20261123-rebuild-drift-fixes/spec.md 論点3。`data-correction`の安全手順)。
 *
 * - 試し実行(既定): 書き換える件数・対応表・対応が付かないidを出すだけ。何も書かない。
 * - 本実行: 対応が付かないidがあれば何も変えずに止める。なければ、トランザクションの前に書き換え対象の行のバックアップ表を作る →
 *   1つのトランザクションで「書き換え → 補正ログ(stored_event_corrections)→ ReadModelの作り直し(1回だけ)→ 検証」。失敗したら全て戻る(バックアップ表は残る)。
 * - 検証: (1)作り直した後の対象行と、書き換え前のライブの対象行が、id以外の全列で一致する (2)対応表どおりに、旧idの行と新idの行が対応する
 *   (3)書き換えた取消イベントのidがすべて作り直した対象行に存在する。他の表の変更は合格条件にせず、表示だけする。
 * 書き換えるのは event_properties だけ。イベントは消さない(二重取消のイベントもすべて同じ対応表で書き換える)。
 */
final class CalendarBulkOperationTargetIdMigrator
{
    public const CORRECTION_KEY = 'calendar_bulk_operation_target_ids';

    public const BACKUP_PREFIX = 'stored_events_backup_calbulk_';

    public const TARGET_TABLE = 'calendar_bulk_operation_targets';

    private const SAMPLE = 20;

    private ?string $backupTable = null;

    public function __construct(
        private readonly CalendarBulkOperationTargetIdPlanner $planner,
        private readonly ReadModelRebuilder $rebuilder,
        private readonly LegacyUserSettingsDetector $userSettingsDetector,
    ) {}

    /** 直近の migrate() で作ったバックアップ表の名前(作っていなければnull)。 */
    public function backupTable(): ?string
    {
        return $this->backupTable;
    }

    /** 前回のバックアップ表の名前を忘れる(まとめのコマンドが実行の前に呼ぶ)。 */
    public function forgetBackup(): void
    {
        $this->backupTable = null;
    }

    /**
     * @return array<string, mixed> 要約。status は nothing(対象なし)か done
     */
    public function migrate(bool $dryRun, float $startedAt, ?string $backupTable = null): array
    {
        $this->backupTable = null;

        // 利用者の設定値が未補正の間は、作り直しが止まる(Projectorが例外にする)ため、まとめのコマンドを案内して止める。
        $userSettingsCount = $this->userSettingsDetector->count();
        if ($userSettingsCount > 0) {
            throw new RuntimeException(LegacyUserSettingsDetector::messageForFixCommand($userSettingsCount));
        }

        $this->assertNoSnapshots();

        $plan = $this->plan();
        $summary = $this->summarize($plan, $dryRun);
        if ($plan['events'] === [] || $dryRun) {
            return $summary;
        }
        $this->assertExecutable($plan);

        $this->createBackup(array_column($plan['events'], 'id'), $backupTable);
        $summary['backup_table'] = $this->backupTable;
        ReadModelRebuilder::heartbeat();

        $limitSeconds = (int) config('read_model_rebuild.time_limit_minutes', 180) * 60;
        // SQLiteではトランザクションの中で外部キーの検査を切れないため、先に切る(ReadModelRebuilder と同じ)。
        Schema::disableForeignKeyConstraints();
        $level = DB::transactionLevel();
        try {
            DB::beginTransaction();

            $beforeRows = $this->captureRows();

            $this->rewriteAll($plan);
            $this->rebuilder->assertWithinTimeLimit($startedAt, $limitSeconds);

            $readModels = $this->rebuilder->rebuild(false, $startedAt);
            $summary['tables'] = $readModels['tables'];
            $summary['changed_tables'] = $readModels['changed_tables'];
            $summary['other_changed_tables'] = $this->otherChangedTables($readModels);

            $this->verify($plan, $beforeRows, $this->captureRows());

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

    /** snapshots に行があれば止める(集約のスナップショットは書き換え後の履歴と一致しない)。 */
    public function assertNoSnapshots(): void
    {
        if (Schema::hasTable('snapshots') && DB::table('snapshots')->exists()) {
            throw new RuntimeException('snapshots テーブルに行があります(集約のスナップショットは書き換え後の履歴と一致しないため、削除してから実行してください)。');
        }
    }

    /**
     * 補正の計画を作る(読み取りのみ)。
     *
     * @return array{events: list<array<string, mixed>>, mapping: array<string, array<string, string>>, unmapped: list<array<string, mixed>>}
     */
    public function plan(): array
    {
        $plan = $this->planner->plan();
        ReadModelRebuilder::heartbeat();

        return $plan;
    }

    /**
     * 要約(試し実行の表示用)。
     *
     * @param  array{events: list<array<string, mixed>>, mapping: array<string, array<string, string>>, unmapped: list<array<string, mixed>>}  $plan
     * @return array<string, mixed>
     */
    public function summarize(array $plan, bool $dryRun): array
    {
        $mappingRows = [];
        foreach ($plan['mapping'] as $operationId => $pairs) {
            foreach ($pairs as $old => $new) {
                $mappingRows[] = ['operation_id' => $operationId, 'old_id' => $old, 'new_id' => $new];
            }
        }
        $idCount = 0;
        foreach ($plan['events'] as $event) {
            $idCount += count($this->idsOf($event['before']));
        }

        return [
            'status' => $plan['events'] === [] ? 'nothing' : 'done',
            'dry_run' => $dryRun,
            'event_count' => count($plan['events']),
            'id_count' => $idCount,
            'mapping_count' => count($mappingRows),
            'mapping' => array_slice($mappingRows, 0, self::SAMPLE),
            'unmapped' => $plan['unmapped'],
            'backup_table' => null,
        ];
    }

    /**
     * 対応が付かないidがあれば止める。
     *
     * @param  array{unmapped: list<array<string, mixed>>}  $plan
     */
    public function assertExecutable(array $plan): void
    {
        if ($plan['unmapped'] !== []) {
            throw new RuntimeException($this->unmappedMessage($plan['unmapped']));
        }
    }

    /**
     * 計画のイベントをすべて書き換える(補正ログも書く。トランザクションの中で呼ぶ)。
     *
     * @param  array{events: list<array<string, mixed>>}  $plan
     */
    public function rewriteAll(array $plan): void
    {
        foreach ($plan['events'] as $event) {
            $this->rewrite($event);
        }
        ReadModelRebuilder::heartbeat();
    }

    /**
     * 作り直しの結果のうち、対象行の表以外で変更のあった表の名前。
     *
     * @param  array{tables: list<array{table: string, changed: bool}>}  $readModels
     * @return list<string>
     */
    public function otherChangedTables(array $readModels): array
    {
        return array_values(array_column(
            array_filter($readModels['tables'], fn (array $row) => $row['changed'] && $row['table'] !== self::TARGET_TABLE),
            'table',
        ));
    }

    /**
     * 現在の対象行を id => (id を除く列の正規化した値) で返す(書き換え前・作り直し後の比較用)。
     *
     * @return array<string, array<string, string>>
     */
    public function captureRows(): array
    {
        return $this->rowsById(new ReadModelFingerprinter);
    }

    /** @param  array{id: int, aggregate_uuid: string, aggregate_version: ?int, event_class: string, raw: string, before: array<string, mixed>, after: array<string, mixed>|null}  $event */
    private function rewrite(array $event): void
    {
        $current = DB::table('stored_events')->where('id', $event['id'])->lockForUpdate()->first();
        if ($current === null || (string) $current->event_properties !== $event['raw']) {
            throw new RuntimeException("stored_events.id={$event['id']} が試し実行の後に変更されました。");
        }

        $afterJson = json_encode($event['after'], JSON_THROW_ON_ERROR);
        DB::table('stored_events')->where('id', $event['id'])->update(['event_properties' => $afterJson]);
        DB::table(StoredEventCorrector::LOG_TABLE)->insert([
            'correction_key' => self::CORRECTION_KEY,
            'stored_event_id' => $event['id'],
            'operation' => StoredEventRewrite::REWRITE,
            'aggregate_uuid' => $current->aggregate_uuid,
            'aggregate_version' => $current->aggregate_version,
            'event_class' => $current->event_class,
            'before_event_properties' => $current->event_properties,
            'after_event_properties' => $afterJson,
            'backup_table' => $this->backupTable,
            'applied_at' => now(),
        ]);
    }

    /**
     * 検証(合格条件1〜3)。
     *
     * @param  array{events: list<array<string, mixed>>, mapping: array<string, array<string, string>>, unmapped: list<array<string, mixed>>}  $plan
     * @param  array<string, array<string, string>>  $before  書き換え前のライブの対象行(id => idを除く列)
     * @param  array<string, array<string, string>>  $after  作り直した後の対象行
     */
    public function verify(array $plan, array $before, array $after): void
    {
        $signatures = fn (array $rows): array => array_count_values(array_map(
            fn (array $row) => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            array_values($rows),
        ));
        $beforeSignatures = $signatures($before);
        $afterSignatures = $signatures($after);
        ksort($beforeSignatures);
        ksort($afterSignatures);
        if ($beforeSignatures !== $afterSignatures) {
            throw new RuntimeException('検証に失敗しました: 作り直した後の対象行が、書き換え前のライブの対象行とid以外の列で一致しません'
                .'(前 '.count($before).' 行・後 '.count($after).' 行)。');
        }

        foreach ($plan['mapping'] as $pairs) {
            foreach ($pairs as $old => $new) {
                if (! isset($before[$old], $after[$new]) || $before[$old] !== $after[$new]) {
                    throw new RuntimeException("検証に失敗しました: 対応表の {$old} -> {$new} の行が一致しません。");
                }
            }
        }

        foreach ($plan['events'] as $event) {
            foreach ($this->idsOf((array) $event['after']) as $id) {
                if (! isset($after[$id])) {
                    throw new RuntimeException("検証に失敗しました: 取消イベント(stored_events.id={$event['id']})のid {$id} が、作り直した対象行にありません。");
                }
            }
        }
    }

    /**
     * 対象行を id => (id を除く列の正規化した値) で返す。
     *
     * @return array<string, array<string, string>>
     */
    private function rowsById(ReadModelFingerprinter $fingerprinter): array
    {
        $data = $fingerprinter->normalizedRows(self::TARGET_TABLE);
        if ($data === null) {
            throw new RuntimeException(self::TARGET_TABLE.' が存在しません。');
        }

        $rows = [];
        foreach ($data['rows'] as $row) {
            $id = (string) $row['id'];
            unset($row['id']);
            $rows[$id] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return list<string>
     */
    private function idsOf(array $properties): array
    {
        return array_map('strval', array_merge((array) ($properties['revertedTargetIds'] ?? []), (array) ($properties['excludedTargetIds'] ?? [])));
    }

    /** @param  list<array{event_id: int, operation_id: string, old_id: string}>  $unmapped */
    private function unmappedMessage(array $unmapped): string
    {
        $items = array_map(fn (array $row) => "stored_events.id={$row['event_id']} 操作={$row['operation_id']} id={$row['old_id']}", array_slice($unmapped, 0, 10));

        return '対応が付かないidが'.count($unmapped).'件あります(過去の事実が決められないため、人が確認してください): '
            .implode(' / ', $items).(count($unmapped) > 10 ? ' ほか'.(count($unmapped) - 10).'件' : '');
    }

    /**
     * トランザクションの前に、書き換える行のバックアップ表を作る(MySQLのDDLは暗黙コミットされるため)。既にあれば失敗する。
     *
     * @param  list<int|string>  $ids
     */
    public function createBackup(array $ids, ?string $name): void
    {
        $backup = $name ?? self::BACKUP_PREFIX.now()->format('YmdHis');
        if (preg_match('/\A[a-z][a-z0-9_]{0,55}\z/', $backup) !== 1) {
            throw new RuntimeException('バックアップ表の名前は英小文字で始まる英小文字・数字・_ の56文字以内にしてください。');
        }
        if (Schema::hasTable($backup)) {
            throw new RuntimeException("バックアップ表 {$backup} が既に存在します。");
        }

        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        DB::statement("CREATE TABLE `{$backup}` AS SELECT * FROM `stored_events` WHERE `id` IN ({$placeholders})", $ids);
        $this->backupTable = $backup;

        $copied = DB::table($backup)->count();
        if ($copied !== count($ids)) {
            throw new RuntimeException("バックアップの行数が一致しません(期待 ".count($ids)."件、実際 {$copied}件)。");
        }
    }
}
