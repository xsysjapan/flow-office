<?php

namespace App\Console\Commands;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveSchedule\Commands\EnsureFutureScheduleGenerated;
use App\Domain\PaidLeaveSchedule\Commands\RunAttendanceRateAssessment;
use App\Domain\PaidLeaveSchedule\Support\ScheduleEntryStatus;
use App\Models\PaidLeaveScheduleEntry;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\DailyBatchTimezoneGroups;
use Illuminate\Console\Command;
use Throwable;

/**
 * docs/changesets/20260906-paid-leave-schedule-assessment/spec.md 論点6・実装対象Phase C。
 * 旧`paid-leave:grant-scheduled`(日次全件評価バッチ、`GrantScheduledPaidLeaveHandler`)を
 * 置換する。cronから毎日実行する想定・べき等(`GenerateCompanyCalendarYearsCommand`と
 * 同じ設計思想)。
 *
 * 対象社員1名ごとに以下の2段階を行う。
 * 1. `EnsureFutureScheduleGenerated`: 現在から1年先までScheduleエントリの存在を保証する。
 * 2. `scheduled_on`が到来済み(社員本人のタイムゾーン基準の「今日」以前)かつまだ
 *    `Scheduled`のままのエントリについて`RunAttendanceRateAssessment`を実行する
 *    (spec.mdはAssessment実行の正確なタイミングを明記していないため、実装判断として
 *    「勤怠データが揃う日付到来後にのみ判定する」という設計意図(spec.md背景・問題点1)に
 *    沿ってこの解釈を採用した)。
 *
 * 対象社員の絞り込みは旧`GrantScheduledPaidLeaveHandler::eligibleUsers()`と同じ条件
 * (`hire_date`が設定済み・`paid_leave_auto_grant_enabled=true`・`usage_start_date`が
 * 未設定または到来済み)を踏襲する。1社員の失敗が他の社員の処理を止めないよう、
 * `paid-leave:migrate-accounts`と同じ「行ごとに収集して継続」パターンを採用する。
 */
class RollPaidLeaveSchedulesCommand extends Command
{
    protected $signature = 'paid-leave:roll-schedules';

    protected $description = '全アクティブ社員について1年先までの有給付与Scheduleエントリを保証し、到来済みエントリの出勤率Assessmentを実行する';

    public function handle(CommandBus $commandBus): int
    {
        $defaultTimezone = SystemSetting::current()->default_timezone;

        $timezoneGroups = DailyBatchTimezoneGroups::resolve(
            null,
            $this->eligibleUsersBaseQuery(),
        );

        $generatedCount = 0;
        $assessedCount = 0;
        $failures = [];

        foreach ($timezoneGroups as $group) {
            $today = $group['today'];

            $users = (clone $this->eligibleUsersBaseQuery())
                ->where(DailyBatchTimezoneGroups::constraint($group['timezone'], $defaultTimezone))
                ->where(fn ($q) => $q->whereNull('usage_start_date')->orWhereDate('usage_start_date', '<=', $today->toDateString()))
                ->get();

            foreach ($users as $user) {
                try {
                    $commandBus->dispatch(new EnsureFutureScheduleGenerated(userId: $user->id));
                    $generatedCount++;
                } catch (Throwable $e) {
                    $failures[] = ['user_id' => $user->id, 'stage' => 'ensure_future_schedule', 'error' => $e->getMessage()];
                    report($e);

                    continue;
                }

                $dueEntries = PaidLeaveScheduleEntry::query()
                    ->where('user_id', $user->id)
                    ->where('status', ScheduleEntryStatus::SCHEDULED)
                    ->whereDate('scheduled_on', '<=', $today->toDateString())
                    ->get();

                foreach ($dueEntries as $entry) {
                    try {
                        $commandBus->dispatch(new RunAttendanceRateAssessment(userId: $user->id, entryId: $entry->id));
                        $assessedCount++;
                    } catch (Throwable $e) {
                        $failures[] = ['user_id' => $user->id, 'stage' => 'run_attendance_rate_assessment', 'entry_id' => $entry->id, 'error' => $e->getMessage()];
                        report($e);
                    }
                }
            }

            $this->warnStaleEligibleEntries($users->pluck('id'), $today);
        }

        $this->info("Schedule生成 {$generatedCount} 件 / Assessment実行 {$assessedCount} 件 / 失敗 ".count($failures).' 件');

        if (count($failures) > 0) {
            $this->table(['user_id', 'stage', 'entry_id', 'error'], array_map(
                fn ($f) => [$f['user_id'], $f['stage'], $f['entry_id'] ?? '-', $f['error']],
                $failures,
            ));
        }

        // 1件の失敗が他の社員の処理を止めないことを優先し(spec.md実装対象・
        // paid-leave:migrate-accountsと同じ方針)、失敗があってもexit codeでのみ呼び出し元に伝える。
        return count($failures) === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function eligibleUsersBaseQuery()
    {
        return User::query()
            ->whereNotNull('hire_date')
            ->where('paid_leave_auto_grant_enabled', true);
    }

    /**
     * spec.md論点8: `Eligible`のまま`scheduled_on`から30日以上未処理のエントリをログ警告する
     * (メール通知は対象外方針のため追加しない)。
     *
     * @param  \Illuminate\Support\Collection<int, string>  $userIds
     */
    private function warnStaleEligibleEntries($userIds, $today): void
    {
        $staleBefore = $today->copy()->subDays(30)->toDateString();

        $staleEntries = PaidLeaveScheduleEntry::query()
            ->whereIn('user_id', $userIds)
            ->where('status', ScheduleEntryStatus::ELIGIBLE)
            ->whereDate('scheduled_on', '<=', $staleBefore)
            ->get(['id', 'user_id', 'scheduled_on']);

        foreach ($staleEntries as $entry) {
            $this->warn("[未処理警告] Scheduleエントリ [{$entry->id}] (user_id={$entry->user_id}, scheduled_on={$entry->scheduled_on->toDateString()}) はEligibleのまま30日以上未処理です。");
        }
    }
}
