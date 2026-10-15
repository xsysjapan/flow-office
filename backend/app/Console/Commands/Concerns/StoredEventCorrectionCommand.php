<?php

namespace App\Console\Commands\Concerns;

use App\Domain\EventSourcing\Correction\StoredEventCorrectionPlan;
use App\Domain\EventSourcing\Correction\StoredEventCorrector;
use Illuminate\Console\Command;

/**
 * stored_events の直接修正を行う運用コマンドの共通基底(.claude/skills/data-correction の安全手順)。
 *
 * - 既定は試し実行: 対象行と修正前後を一覧に出すだけで、何も書き込まない。
 * - --apply: バックアップテーブルを作成してから1トランザクションで修正し、補正ログへ記録する。
 *   --backup-table を省略すると stored_events_backup_YYYYMMDDHHMMSS を使う(既存名は停止する)。
 * - 子クラスのsignatureに {--apply} {--backup-table=} を含めること(このクラスは定義しない)。
 *
 * 子クラスは plan() で補正計画(対象の stored_events.id と書き換え内容)を返す。計画の中身はコードとテストで固定する。
 */
abstract class StoredEventCorrectionCommand extends Command
{
    /** 補正計画を作る(対象の抽出・書き換え後の値の決定)。 */
    abstract protected function plan(): StoredEventCorrectionPlan;

    final public function handle(StoredEventCorrector $corrector): int
    {
        $plan = $this->plan();
        $rows = $corrector->preview($plan);

        $this->info("補正キー: {$plan->correctionKey}");
        $this->line($plan->description);
        $this->table(
            ['stored_event.id', '集約ID', '版', 'イベント', '操作', '状態', '修正前', '修正後'],
            array_map(fn (array $row) => [
                $row['stored_event_id'],
                $row['aggregate_uuid'] ?? '-',
                $row['aggregate_version'] ?? '-',
                $row['event_class'] ?? '-',
                $row['operation'],
                $row['status'] === 'pending' ? '修正対象' : '修正済み(触らない)',
                $this->summarize($row['before']),
                $this->summarize($row['after']),
            ], $rows),
        );

        $pending = count(array_filter($rows, fn (array $row) => $row['status'] === 'pending'));
        $this->info("対象 ".count($rows)."件のうち修正対象 {$pending}件です。");

        if (! $this->option('apply')) {
            $this->warn('試し実行です。内容を確認し、問題なければ --apply で本実行してください(バックアップを作成してから修正します)。');

            return self::SUCCESS;
        }

        $backupTable = (string) ($this->option('backup-table') ?: 'stored_events_backup_'.now()->format('YmdHis'));
        $result = $corrector->apply($plan, $backupTable);

        $this->info("修正: {$result->applied}件、修正済みのため触らず: {$result->alreadyApplied}件".
            ($result->backupTable !== null ? "、バックアップ: {$result->backupTable}" : '、修正対象が無いためバックアップは作成していません'));
        $this->warn('続けて event-sourcing:replay で対象のReadModelを再生成し、検証SQLで結果を確認してください。');

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>|null  $properties */
    private function summarize(?array $properties): string
    {
        if ($properties === null) {
            return '-';
        }

        $json = json_encode($properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

        return mb_strlen($json) > 160 ? mb_substr($json, 0, 160).'…' : $json;
    }
}
