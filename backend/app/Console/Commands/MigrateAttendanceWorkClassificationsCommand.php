<?php

namespace App\Console\Commands;

use App\Console\Attributes\AdminExecutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * 既存の勤怠計算データを所定内外×法定内外+法定休日の5区分へ移行する。
 *
 * ProjectionをSQLで推測して更新せず、計算イベントの補正、Projection再生、確定済み
 * 月次snapshotの再計算を既存の専用コマンドで順に実行する。
 */
#[AdminExecutable(
    label: '勤怠5区分データ移行',
    rules: [
        'apply' => ['nullable', 'boolean'],
        'backup-table' => ['nullable', 'regex:/\A[a-z][a-z0-9_]{0,55}\z/'],
        'year-month' => ['nullable', 'date_format:Y-m'],
    ],
    ui: [
        'apply' => ['control' => 'checkbox'],
        'backup-table' => ['control' => 'text'],
        'year-month' => ['control' => 'year-month'],
    ],
)]
final class MigrateAttendanceWorkClassificationsCommand extends Command
{
    protected $signature = 'attendance:migrate-work-classifications
        {--apply : バックアップ作成後に5区分への移行を適用する}
        {--backup-table=stored_events_backup_attendance_work_classifications : 補正前イベントのバックアップテーブル名}
        {--year-month= : 月次スナップショットの対象を指定の年月(YYYY-MM)のみに絞る}';

    protected $description = '既存の勤怠計算イベント・Projection・月次スナップショットを5区分へ一括移行する';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $yearMonth = $this->option('year-month');

        if (! $apply) {
            $this->info('事前確認を実行します。データは変更しません。');

            if (! $this->executeStep('attendance:normalize-calculation-events')) {
                return self::FAILURE;
            }

            return $this->executeStep('attendance:recalculate-month-snapshots', array_filter([
                '--year-month' => $yearMonth,
                '--dry-run' => true,
            ], fn ($value) => $value !== null)) ? self::SUCCESS : self::FAILURE;
        }

        $this->warn('勤怠5区分データ移行を適用します。');
        if (! $this->executeStep('attendance:normalize-calculation-events', [
            '--apply' => true,
            '--backup-table' => (string) $this->option('backup-table'),
        ])) {
            return self::FAILURE;
        }

        if (! $this->executeStep('attendance:rebuild-calculation-projections')) {
            return self::FAILURE;
        }

        if (! $this->executeStep('attendance:recalculate-month-snapshots', array_filter([
            '--year-month' => $yearMonth,
        ], fn ($value) => $value !== null))) {
            return self::FAILURE;
        }

        $this->info('勤怠5区分データ移行が完了しました。');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $parameters */
    private function executeStep(string $command, array $parameters = []): bool
    {
        $this->newLine();
        $this->info("{$command} を実行します。");

        $exitCode = Artisan::call($command, $parameters, $this->output);
        if ($exitCode !== self::SUCCESS) {
            $this->error("{$command} に失敗したため、移行を中断します。");

            return false;
        }

        return true;
    }
}
