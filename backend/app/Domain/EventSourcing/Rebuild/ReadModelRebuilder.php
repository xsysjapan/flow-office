<?php

namespace App\Domain\EventSourcing\Rebuild;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\EventSourcing\Projectionist;
use Throwable;

/**
 * 全ReadModelを1つのトランザクションで空にして、全Projectorを`stored_events`の先頭から再生する
 * (docs/changesets/20261025-admin-read-model-rebuild/spec.md 論点2・6、docs/changesets/20261025-read-model-rebuild-admin-command/spec.md 論点2)。
 *
 * 手順: 件数とハッシュを取る → 外部キーの検査を切る → トランザクション開始 → 全テーブルをDELETE → 全Projectorをreplay →
 * 件数とハッシュを取り直す → 時間の上限を確かめる → 試し実行ならロールバック・本実行ならコミット
 * (例外ならロールバックして再送出)→ 外部キーの検査を戻す。TRUNCATEは暗黙にコミットされるため使わない。
 * 対象は`ReadModelTableLister::tables()`(`leave:rebuild-event-store --list-readmodels`と同じ一覧)のうちDBに存在するもの。
 * 心拍は`Cache::store('file')`(既定のキャッシュはDBで、トランザクションの中の書き込みは他の接続から見えないため)。
 */
class ReadModelRebuilder
{
    public const HEARTBEAT_KEY = 'read-model-rebuild:heartbeat';

    public const HEARTBEAT_TTL_SECONDS = 86400;

    public const HEARTBEAT_EVENT_INTERVAL = 1000;

    /** 利用者・権限のテーブル。ここに変更があると、イベントを経ずに作られた行がある(出力の先頭に警告を出す)。 */
    public const ACCOUNT_TABLES = ['users', 'memberships', 'groups', 'roles', 'role_assignments', 'permission_role', 'role_features', 'group_feature_assignments'];

    public const TIME_LIMIT_MESSAGE = '時間の上限を超えたため中止しました。';

    /**
     * @return array{dry_run: bool, tables: list<array{table: string, before: int, after: int, changed: bool}>, changed_tables: int}
     */
    public function rebuild(bool $dryRun): array
    {
        $startedAt = microtime(true);
        $limitSeconds = (int) config('read_model_rebuild.time_limit_minutes', 180) * 60;

        $tables = $this->existingTables();
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = (new ReadModelFingerprinter)->fingerprint($table);
            self::heartbeat();
        }

        $summary = null;
        Schema::disableForeignKeyConstraints();
        $level = DB::transactionLevel();
        try {
            DB::beginTransaction();

            foreach ($tables as $table) {
                DB::table($table)->delete();
            }
            self::heartbeat();

            $count = 0;
            $projectionist = app(Projectionist::class);
            $projectionist->replay($projectionist->getProjectors(), 0, function () use (&$count, $startedAt, $limitSeconds): void {
                if (++$count % self::HEARTBEAT_EVENT_INTERVAL === 0) {
                    self::heartbeat();
                    $this->assertWithinTimeLimit($startedAt, $limitSeconds);
                }
            });
            self::heartbeat();

            $rows = [];
            foreach ($tables as $table) {
                $after = (new ReadModelFingerprinter)->fingerprint($table);
                $rows[] = [
                    'table' => $table,
                    'before' => (int) ($before[$table]['count'] ?? 0),
                    'after' => (int) ($after['count'] ?? 0),
                    'changed' => ($before[$table]['hash'] ?? null) !== ($after['hash'] ?? null),
                ];
                self::heartbeat();
            }
            $summary = [
                'dry_run' => $dryRun,
                'tables' => $rows,
                'changed_tables' => count(array_filter($rows, fn (array $row) => $row['changed'])),
            ];

            $this->assertWithinTimeLimit($startedAt, $limitSeconds);

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

    /** 作り直しで空にするテーブル(DBに存在するもの)。 */
    public function existingTables(): array
    {
        return array_values(array_filter(
            array_keys((new ReadModelTableLister)->tables(app_path('Domain'))),
            fn (string $table) => Schema::hasTable($table),
        ));
    }

    /**
     * 変更のあった利用者・権限のテーブル名(summaryの`tables`から)。
     *
     * @param  list<array{table: string, before: int, after: int, changed: bool}>  $rows
     * @return list<string>
     */
    public static function changedAccountTables(array $rows): array
    {
        return array_values(array_map(
            fn (array $row) => $row['table'],
            array_filter($rows, fn (array $row) => $row['changed'] && in_array($row['table'], self::ACCOUNT_TABLES, true)),
        ));
    }

    public function assertWithinTimeLimit(float $startedAt, int $limitSeconds): void
    {
        if (microtime(true) - $startedAt >= $limitSeconds) {
            throw new RuntimeException(self::TIME_LIMIT_MESSAGE);
        }
    }

    public static function heartbeat(): void
    {
        Cache::store('file')->put(self::HEARTBEAT_KEY, time(), self::HEARTBEAT_TTL_SECONDS);
    }

    /** 最後の心拍のUNIXタイム(無ければnull)。 */
    public static function lastHeartbeat(): ?int
    {
        $value = Cache::store('file')->get(self::HEARTBEAT_KEY);

        return $value === null ? null : (int) $value;
    }

    public static function clearHeartbeat(): void
    {
        Cache::store('file')->forget(self::HEARTBEAT_KEY);
    }
}
