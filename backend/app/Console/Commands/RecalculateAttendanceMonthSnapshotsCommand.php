<?php

namespace App\Console\Commands;

use App\Domain\Attendance\Commands\RecalculateAttendanceMonthSnapshot;
use App\Domain\EventSourcing\CommandBus;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use Illuminate\Console\Command;

/**
 * データ補正用(1回限りの手動実行を想定、cron登録はしない): 集計ロジックの追加・修正
 * (MonthlyOvertimeCalculator::calculateCategoryTotals())を、既存の提出済み/承認済み/締め済みの
 * 月次勤怠のsnapshot_jsonへ反映する。対象月の日次実績は提出時にロックされ変更されないため、
 * 再計算しても値が変わるのは集計結果のみで実績そのものは変わらない(安全に再実行できる)。
 * 差戻し中(returned)・未提出(not_submitted)は対象外(RecalculateAttendanceMonthSnapshotHandler参照)。
 */
class RecalculateAttendanceMonthSnapshotsCommand extends Command
{
    protected $signature = 'attendance:recalculate-month-snapshots
        {--year-month= : 対象を指定の年月(YYYY-MM)のみに絞る}
        {--dry-run : 実際には再計算せず対象件数のみ表示する}';

    protected $description = '提出済み/承認済み/締め済みの月次勤怠のsnapshot_jsonを現在の集計ロジックで再計算する';

    public function handle(CommandBus $commandBus): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $yearMonth = $this->option('year-month');

        $months = AttendanceMonth::query()
            ->whereIn('status', [
                AttendanceMonthStatus::SUBMITTED,
                AttendanceMonthStatus::APPROVED,
                AttendanceMonthStatus::CLOSED,
            ])
            ->when($yearMonth !== null, fn ($query) => $query->where('year_month', $yearMonth))
            ->get();

        foreach ($months as $month) {
            $this->line("{$month->user_id} / {$month->year_month} (attendance_months.id={$month->id}) を再計算します。");

            if (! $dryRun) {
                $commandBus->dispatch(new RecalculateAttendanceMonthSnapshot($month->id));
            }
        }

        $count = $months->count();
        $this->info($dryRun ? "{$count} 件が対象です(--dry-runのため未計算)。" : "{$count} 件を再計算しました。");

        return self::SUCCESS;
    }
}
