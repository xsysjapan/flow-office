<?php

namespace App\Domain\EventSourcing\Rebuild;

use App\Domain\EventSourcing\Correction\StoredEventCorrector;
use App\Domain\EventSourcing\Correction\StoredEventRewrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * 利用者の作成系イベントにタイムゾーン・テナントIDを書き足し、ReadModelを作り直して検証する
 * (docs/changesets/20261124-user-projection-settings-at-replay/spec.md 論点2。`data-correction`の安全手順)。
 *
 * - 試し実行(既定): 書き足す件数・上書きの一覧・ライブの値と設定の履歴の値が違う利用者の一覧・補えないイベントを出すだけ。何も書かない。
 * - 本実行: snapshots に行があれば、補えないイベントがあれば、何も変えずに止める。なければ、トランザクションの前に書き換える行のバックアップ表を作る →
 *   1つのトランザクションで「書き換え → 補正ログ(stored_event_corrections)→ ReadModelの作り直し(1回だけ)→ 検証」。失敗したら全て戻る(バックアップ表は残る)。
 * - 検証: 作り直した結果、users・external_identities が書き換え前のライブと一致し、他の全表も変わらない(changed_tables が 0)。
 * 書き換えるのは event_properties だけ。イベントは消さない。
 */
final class UserSettingsAtReplayMigrator
{
    public const CORRECTION_KEY = 'user_settings_at_replay';

    public const BACKUP_PREFIX = 'stored_events_backup_usersettings_';

    private const SAMPLE = 20;

    private ?string $backupTable = null;

    public function __construct(
        private readonly UserSettingsAtReplayPlanner $planner,
        private readonly ReadModelRebuilder $rebuilder,
        private readonly LegacyCalendarBulkOperationTargetIdDetector $bulkDetector,
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

        // 一括操作の取消イベントが未補正の間は、作り直しで旧idの行が消えて対応表を作れなくなるため、まとめのコマンドを案内して止める。
        $bulkCount = $this->bulkDetector->count();
        if ($bulkCount > 0) {
            throw new RuntimeException(LegacyCalendarBulkOperationTargetIdDetector::messageForFixCommand($bulkCount));
        }

        $this->assertNoSnapshots();

        $plan = $this->plan();
        $summary = $this->summarize($plan, $dryRun);
        if (($plan['events'] === [] && $plan['unresolved'] === []) || $dryRun) {
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

            $this->rewriteAll($plan);
            $this->rebuilder->assertWithinTimeLimit($startedAt, $limitSeconds);

            $readModels = $this->rebuilder->rebuild(false, $startedAt);
            $summary['tables'] = $readModels['tables'];
            $summary['changed_tables'] = $readModels['changed_tables'];

            $this->verify($readModels, []);

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

    /** snapshots に利用者の集約の行があれば止める(集約のスナップショットは書き換え後の履歴と一致しない)。 */
    public function assertNoSnapshots(): void
    {
        if (Schema::hasTable('snapshots') && DB::table('snapshots')->exists()) {
            throw new RuntimeException('snapshots テーブルに行があります(集約のスナップショットは書き換え後の履歴と一致しないため、削除してから実行してください)。');
        }
    }

    /**
     * 補正の計画を作る(読み取りのみ)。
     *
     * @return array<string, mixed>
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
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public function summarize(array $plan, bool $dryRun): array
    {
        $changes = [];
        foreach (array_slice($plan['events'], 0, self::SAMPLE) as $event) {
            $changes[] = ['event_id' => $event['id'], 'event_class' => $event['event_class'], 'changes' => $event['changes']];
        }

        return [
            'status' => $plan['events'] === [] ? 'nothing' : 'done',
            'dry_run' => $dryRun,
            'event_count' => count($plan['events']),
            'timezone_filled' => $plan['timezone_filled'],
            'tenant_filled' => $plan['tenant_filled'],
            'tenant_filled_null' => $plan['tenant_filled_null'],
            'tenant_overwrite_count' => count($plan['tenant_overwrites']),
            'tenant_overwrites' => array_slice($plan['tenant_overwrites'], 0, self::SAMPLE),
            'timezone_difference_count' => count($plan['timezone_differences']),
            'timezone_differences' => array_slice($plan['timezone_differences'], 0, self::SAMPLE),
            'unresolved' => $plan['unresolved'],
            'sample' => $changes,
            'backup_table' => null,
        ];
    }

    /**
     * 補えないイベントがあれば止める。
     *
     * @param  array<string, mixed>  $plan
     */
    public function assertExecutable(array $plan): void
    {
        if ($plan['unresolved'] !== []) {
            $items = array_map(fn (array $row) => "stored_events.id={$row['event_id']} {$row['event_class']}: {$row['reason']}", array_slice($plan['unresolved'], 0, 10));

            throw new RuntimeException('補えない値が'.count($plan['unresolved']).'件あります(過去の事実が決められないため、人が確認してください): '
                .implode(' / ', $items).(count($plan['unresolved']) > 10 ? ' ほか'.(count($plan['unresolved']) - 10).'件' : ''));
        }
    }

    /**
     * 計画のイベントをすべて書き換える(補正ログも書く。トランザクションの中で呼ぶ)。
     *
     * @param  array<string, mixed>  $plan
     */
    public function rewriteAll(array $plan): void
    {
        foreach ($plan['events'] as $event) {
            $this->rewrite($event);
        }
        ReadModelRebuilder::heartbeat();
    }

    /**
     * 検証: 作り直しの結果、変更のあった表が許可した表(`$allowedChanged`)だけであること。
     * users・external_identities は常に許可しない(書き換え前のライブと一致すること)。
     *
     * @param  array{tables: list<array{table: string, changed: bool}>}  $readModels
     * @param  list<string>  $allowedChanged
     */
    public function verify(array $readModels, array $allowedChanged): void
    {
        $changed = array_values(array_column(array_filter($readModels['tables'], fn (array $row) => $row['changed']), 'table'));
        foreach (['users', 'external_identities'] as $table) {
            if (in_array($table, $changed, true)) {
                throw new RuntimeException("検証に失敗しました: 作り直した後の {$table} が、書き換え前のライブと一致しません。");
            }
        }
        $unexpected = array_values(array_diff($changed, $allowedChanged));
        if ($unexpected !== []) {
            throw new RuntimeException('検証に失敗しました: 作り直しで変更のあった表があります: '.implode(', ', $unexpected));
        }
    }

    /** @param  array<string, mixed>  $event */
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
            throw new RuntimeException('バックアップの行数が一致しません(期待 '.count($ids)."件、実際 {$copied}件)。");
        }
    }
}
