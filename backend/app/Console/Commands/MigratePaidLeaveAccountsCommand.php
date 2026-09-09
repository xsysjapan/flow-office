<?php

namespace App\Console\Commands;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveAccount\Commands\MigratePaidLeaveAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * 最終Phase(データ移行、docs/changesets/20260906-paid-leave-domain-redesign/spec.md
 * 「既存データ移行」参照)向けの一括投入コマンド。UI実装は当変更セットのスコープ外
 * (spec.md「対象外」)のため、UIレスなartisanコマンドとして提供する。
 *
 * 入力はJSONファイル(社員ごとにGrant一覧をネストして持つため、フラットな行構造の
 * CSVでは自然に表現できず、この移行専用コマンドに限りJSON形式のみを受け付ける)。
 * トップレベルは以下の形の配列:
 *
 * [
 *   {
 *     "user_id": "uuid",
 *     "cutover_date": "2026-04-01",
 *     "grants": [
 *       {
 *         "original_granted_on": "2025-04-01",   // モードA/Bのみ。モードCはnull可
 *         "original_granted_days": 10.0,          // モードAのみ。モードB/Cはnull可
 *         "remaining_days_at_cutover": 6.0,        // 必須。全モード共通の「切替時点の実際の残高」
 *         "expires_on": "2027-04-01",
 *         "mode": "A",                             // "A"|"B"|"C"(監査用メタデータとして記録)
 *         "notes": "旧システムのGrant#123に対応"     // 任意
 *       }
 *     ]
 *   }
 * ]
 *
 * 1社員分の移行が失敗しても処理を止めず(1行の不正データがバッチ全体を落とさない)、
 * 成功・失敗を行ごとに収集して最後にまとめて報告する。移行は口座ごとに一度きりの
 * 操作のため、既にGrantが存在する口座を指定した行は失敗として報告される
 * (`DomainRuleException`、"1件失敗しても残りは処理を継続"の対象内)。
 */
class MigratePaidLeaveAccountsCommand extends Command
{
    protected $signature = 'paid-leave:migrate-accounts {file : 移行データを含むJSONファイルのパス} {--dry-run : Commandを発行せず、入力の形式検証のみ行う}';

    protected $description = '旧システムからの年次有給休暇データをJSONファイルから一括移行する(cutover専用・社員ごとに一度きり)';

    public function handle(CommandBus $commandBus): int
    {
        $path = $this->argument('file');

        if (! File::exists($path)) {
            $this->error("ファイルが見つかりません: {$path}");

            return self::FAILURE;
        }

        $rows = json_decode(File::get($path), true);

        if (! is_array($rows)) {
            $this->error('JSONの形式が不正です(配列を想定しています)。');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $successes = 0;
        $failures = [];

        foreach ($rows as $index => $row) {
            $rowLabel = $row['user_id'] ?? "index={$index}";

            try {
                $command = $this->buildCommand($row);

                if (! $dryRun) {
                    $commandBus->dispatch($command);
                }

                $successes++;
                $this->line("[OK] {$rowLabel}");
            } catch (Throwable $e) {
                $failures[] = ['user_id' => $rowLabel, 'error' => $e->getMessage()];
                $this->warn("[NG] {$rowLabel}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info(($dryRun ? '(dry-run) ' : '')."成功 {$successes} 件 / 失敗 ".count($failures).' 件');

        if (count($failures) > 0) {
            $this->table(['user_id', 'error'], $failures);
        }

        // 一部失敗があってもバッチ全体は継続済み。失敗行があったこと自体はexit codeで
        // 呼び出し元(cron/手動実行)に伝えるが、成功した行のCommandは取り消さない
        // (Migrationは口座単位で独立しており、1行の失敗が他行の整合性に影響しないため)。
        return count($failures) === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function buildCommand(array $row): MigratePaidLeaveAccount
    {
        if (! isset($row['user_id'], $row['cutover_date'])) {
            throw new \InvalidArgumentException('user_id/cutover_dateは必須です。');
        }

        $grants = [];

        foreach (($row['grants'] ?? []) as $g) {
            if (! isset($g['remaining_days_at_cutover'], $g['expires_on'], $g['mode'])) {
                throw new \InvalidArgumentException('各grantsにはremaining_days_at_cutover/expires_on/modeが必須です。');
            }

            if (! in_array($g['mode'], ['A', 'B', 'C'], true)) {
                throw new \InvalidArgumentException("modeはA/B/Cのいずれかである必要があります: {$g['mode']}");
            }

            $grants[] = [
                'grantId' => $g['grant_id'] ?? null,
                'originalGrantedOn' => $g['original_granted_on'] ?? null,
                'originalGrantedDays' => isset($g['original_granted_days']) ? (float) $g['original_granted_days'] : null,
                'remainingDaysAtCutover' => (float) $g['remaining_days_at_cutover'],
                'expiresOn' => $g['expires_on'],
                'mode' => $g['mode'],
                'notes' => $g['notes'] ?? null,
            ];
        }

        return new MigratePaidLeaveAccount(
            userId: $row['user_id'],
            cutoverDate: $row['cutover_date'],
            grants: $grants,
        );
    }
}
