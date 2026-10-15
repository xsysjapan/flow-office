<?php

namespace App\Domain\EventSourcing\Correction;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

/**
 * stored_events の直接修正(書き換え・削除)を安全に行う共通処理(.claude/skills/data-correction の安全手順)。
 *
 * - preview(): 試し実行。対象と修正前後を返すだけで、何も書き込まない。
 * - apply(): 修正対象の行をバックアップテーブルへ複写してから(MySQLのDDLは暗黙コミットされるため
 *   トランザクションの外で)、1トランザクションで書き換え・削除し、補正ログへ修正前のpayloadを残す。
 *   削除後・書き換え後に同じ集約の版(aggregate_version)が1から続けて欠けなく並んでいることを検証し、
 *   崩れていれば全体を巻き戻す。
 * - 冪等: 補正ログ(correction_key + stored_event_id)に既にある行は再修正しない。
 *
 * 補正イベントの追記(attendance_day.corrected等)はこのクラスの対象外で、補正用Commandを発行する。
 * 修正後のReadModelはevent-sourcing:replayで再生成し、その結果を検証SQLで確認する(手順は変更セットの
 * 補正手順を参照)。
 */
final class StoredEventCorrector
{
    public const LOG_TABLE = 'stored_event_corrections';

    /**
     * 試し実行: 対象の行と修正前後を返す。status は pending(これから修正する)か applied(補正ログにあり触らない)。
     *
     * @return list<array{stored_event_id: int, operation: string, aggregate_uuid: ?string, aggregate_version: ?int, event_class: ?string, before: ?array<string, mixed>, after: ?array<string, mixed>, status: string}>
     */
    public function preview(StoredEventCorrectionPlan $plan): array
    {
        $applied = array_fill_keys(
            array_map('intval', DB::table(self::LOG_TABLE)
                ->where('correction_key', $plan->correctionKey)
                ->pluck('stored_event_id')
                ->all()),
            true,
        );

        $rows = [];
        foreach ($plan->rewrites as $rewrite) {
            $isApplied = isset($applied[$rewrite->storedEventId]);
            $event = DB::table('stored_events')->where('id', $rewrite->storedEventId)->first();

            if ($event === null) {
                if (! $isApplied) {
                    throw new RuntimeException("stored_events.id={$rewrite->storedEventId} が存在しません。");
                }
                $rows[] = [
                    'stored_event_id' => $rewrite->storedEventId,
                    'operation' => $rewrite->operation,
                    'aggregate_uuid' => null,
                    'aggregate_version' => null,
                    'event_class' => null,
                    'before' => null,
                    'after' => null,
                    'status' => 'applied',
                ];

                continue;
            }

            $rows[] = [
                'stored_event_id' => $rewrite->storedEventId,
                'operation' => $rewrite->operation,
                'aggregate_uuid' => $event->aggregate_uuid,
                'aggregate_version' => $event->aggregate_version !== null ? (int) $event->aggregate_version : null,
                'event_class' => $event->event_class,
                'before' => $this->decode($event->event_properties),
                'after' => $rewrite->operation === StoredEventRewrite::REWRITE ? $rewrite->eventProperties : null,
                'status' => $isApplied ? 'applied' : 'pending',
            ];
        }

        return $rows;
    }

    /**
     * 本実行。バックアップテーブルを作成し、未修正の行を1トランザクションで修正して補正ログへ記録する。
     */
    public function apply(StoredEventCorrectionPlan $plan, string $backupTable): StoredEventCorrectionResult
    {
        $this->assertNewBackupTable($backupTable);

        $preview = $this->preview($plan);
        $pending = array_values(array_filter($preview, fn (array $row) => $row['status'] === 'pending'));
        $alreadyApplied = count($preview) - count($pending);

        if ($pending === []) {
            return new StoredEventCorrectionResult(applied: 0, alreadyApplied: $alreadyApplied, backupTable: null);
        }

        $this->createBackup($backupTable, array_column($pending, 'stored_event_id'));

        DB::transaction(function () use ($plan, $pending, $backupTable): void {
            $affectedAggregates = [];

            foreach ($pending as $change) {
                $id = (int) $change['stored_event_id'];
                $current = DB::table('stored_events')->where('id', $id)->lockForUpdate()->first();
                if ($current === null) {
                    throw new RuntimeException("stored_events.id={$id} が試し実行の後に存在しなくなりました。");
                }

                $afterJson = $change['after'] !== null ? json_encode($change['after'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null;

                if ($change['operation'] === StoredEventRewrite::DELETE) {
                    DB::table('stored_events')->where('id', $id)->delete();
                } else {
                    DB::table('stored_events')->where('id', $id)->update(['event_properties' => $afterJson]);
                }

                DB::table(self::LOG_TABLE)->insert([
                    'correction_key' => $plan->correctionKey,
                    'stored_event_id' => $id,
                    'operation' => $change['operation'],
                    'aggregate_uuid' => $current->aggregate_uuid,
                    'aggregate_version' => $current->aggregate_version,
                    'event_class' => $current->event_class,
                    'before_event_properties' => $current->event_properties,
                    'after_event_properties' => $afterJson,
                    'backup_table' => $backupTable,
                    'applied_at' => now(),
                ]);

                if ($current->aggregate_uuid !== null) {
                    $affectedAggregates[(string) $current->aggregate_uuid] = true;
                }
            }

            foreach (array_keys($affectedAggregates) as $aggregateUuid) {
                $this->assertContiguousVersions((string) $aggregateUuid);
            }
        });

        return new StoredEventCorrectionResult(
            applied: count($pending),
            alreadyApplied: $alreadyApplied,
            backupTable: $backupTable,
        );
    }

    private function assertNewBackupTable(string $table): void
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,55}\z/', $table) !== 1) {
            throw new InvalidArgumentException('バックアップテーブル名は英小文字で始まる英小文字・数字・_ の56文字以内で指定してください。');
        }
        if (Schema::hasTable($table)) {
            throw new RuntimeException("バックアップテーブル {$table} が既に存在します。別の名前を指定してください。");
        }
    }

    /** @param  list<int|string>  $ids */
    private function createBackup(string $table, array $ids): void
    {
        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        DB::statement("CREATE TABLE `{$table}` AS SELECT * FROM `stored_events` WHERE `id` IN ({$placeholders})", $ids);

        $copied = DB::table($table)->count();
        if ($copied !== count($ids)) {
            throw new RuntimeException("バックアップの行数が一致しません(期待 ".count($ids)."件、実際 {$copied}件)。");
        }
    }

    /**
     * 集約の版が1から続けて欠けなく並んでいることを確かめる(削除による版の欠番を検出する)。
     */
    private function assertContiguousVersions(string $aggregateUuid): void
    {
        $versions = DB::table('stored_events')
            ->where('aggregate_uuid', $aggregateUuid)
            ->orderBy('aggregate_version')
            ->pluck('aggregate_version')
            ->map(fn ($version) => (int) $version)
            ->all();

        for ($i = 1; $i < count($versions); $i++) {
            if ($versions[$i] !== $versions[$i - 1] + 1) {
                throw new RuntimeException(
                    "集約 {$aggregateUuid} の版が連続しません({$versions[$i - 1]} の次が {$versions[$i]})。版の振り直しを含む計画に作り直してください。"
                );
            }
        }
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
