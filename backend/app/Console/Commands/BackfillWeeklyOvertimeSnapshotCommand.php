<?php

namespace App\Console\Commands;

use App\Domain\Attendance\Projectors\AttendanceMonthProjector;
use App\Domain\Attendance\Services\WeeklyOvertimeCalculator;
use Illuminate\Console\Command;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

/**
 * 週40時間超残業(weekly_statutory_excess_overtime_minutes)をMonthlyOvertimeCalculatorの
 * 月合計に含めて確定値化する対応より前に提出済みだったattendance_month.submittedイベントには、
 * このキーがevent_properties.snapshotに存在しない。attendance_months.snapshot_jsonは
 * AttendanceMonthProjectorがイベントのsnapshotをそのまま保存するだけで、Projector側の
 * ロジックを直しても既存イベントの中身までは変わらないため再生(replay)だけでは補完されない。
 * この値は提出時点で本来計算されているべきだった派生値の欠落であり新たな業務事実の追記では
 * ないため、stored_eventsのevent_properties.snapshotへ直接補完する(1回限りの手動実行。
 * cron常駐は前提としない)。
 *
 * 補完後にAttendanceMonthProjectorを再生(event-sourcing:replay)し、
 * attendance_months.snapshot_jsonへ反映する(本コマンドの最後で自動実行する)。
 */
class BackfillWeeklyOvertimeSnapshotCommand extends Command
{
    protected $signature = 'attendance:backfill-weekly-overtime-snapshot
                            {--dry-run : 補完対象と計算結果を表示するだけで書き込みは行わない}';

    protected $description = '週40時間超残業の月合計を、過去のattendance_month.submittedイベントのsnapshotへ補完する';

    public function handle(WeeklyOvertimeCalculator $weeklyOvertimeCalculator): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $targets = EloquentStoredEvent::query()
            ->where('event_class', 'attendance_month.submitted')
            ->get()
            ->filter(fn (EloquentStoredEvent $event) => ! array_key_exists(
                'weekly_statutory_excess_overtime_minutes',
                $event->event_properties['snapshot'] ?? [],
            ));

        if ($targets->isEmpty()) {
            $this->info('補完対象のイベントはありませんでした。');

            return self::SUCCESS;
        }

        $this->info("{$targets->count()} 件のattendance_month.submittedイベントを補完します。");

        foreach ($targets as $event) {
            $properties = $event->event_properties;
            $userId = $properties['userId'];
            $yearMonth = $properties['yearMonth'];

            $weeklyMinutes = array_sum(array_column(
                $weeklyOvertimeCalculator->calculateForMonth($userId, $yearMonth),
                'weekly_statutory_excess_overtime_minutes',
            ));

            $this->line("  - user_id={$userId} year_month={$yearMonth} weekly_statutory_excess_overtime_minutes={$weeklyMinutes}");

            if ($dryRun) {
                continue;
            }

            $properties['snapshot']['weekly_statutory_excess_overtime_minutes'] = $weeklyMinutes;
            $event->event_properties = $properties;
            $event->save();
        }

        if ($dryRun) {
            $this->comment('--dry-runのため書き込みは行いませんでした。');

            return self::SUCCESS;
        }

        $this->call('event-sourcing:replay', ['projector' => [AttendanceMonthProjector::class], '--force' => true]);

        return self::SUCCESS;
    }
}
