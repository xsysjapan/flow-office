<?php

namespace App\Console\Commands;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveSchedule\Commands\RecalculateFutureSchedule;
use App\Domain\PaidLeaveSchedule\Support\ScheduleCandidateGenerator;
use App\Models\User;
use App\Support\BusinessDate;
use Illuminate\Console\Command;

/**
 * 全アクティブ社員(在籍・自動付与有効・入社日あり)について、今日から1年先までの付与予定を
 * 毎日再計算する日次cronコマンド(`paid-leave:roll-schedules`)。
 *
 * 付与予定の再計算だけを行い、出勤率の判定はしない(判定は`paid-leave:assess-schedules`。毎月1日と手動)。
 * 「今日」は既定のタイムゾーンの日付(`BusinessDate`)。
 * 対象社員ごとに`RecalculateFutureSchedule`を発行する。`fromDate`は明日(今日の行は触らない。
 * 毎月1日の判定を、同じ日の再計算で置き換えないため)。
 * 再計算は冪等で、区分・日数が変わらない行には何も記録しない。そのため、先の月に登録した
 * 勤務形態の割当が有効になった月替わりや、各種の取りこぼしは、翌日の実行で直る。
 * 候補が無い社員(付与ルールに合わない等)でも再計算を発行し、未確定の未来の行は取り消される。
 * 個別修正の行と、付与済みの行は変えない。1年より先の未確定の行(`paid-leave:schedule:rebuild --to`で
 * 作ったもの)は、候補に無いため取り消される。
 *
 * 旧`paid-leave:grant-scheduled`(`GrantScheduledPaidLeaveCommand`)を置換する
 * (spec.md 論点10)。
 */
class RollPaidLeaveSchedulesCommand extends Command
{
    protected $signature = 'paid-leave:roll-schedules';

    protected $description = '全アクティブ社員について、明日から1年先までの有給付与予定を再計算する';

    public function handle(CommandBus $commandBus, ScheduleCandidateGenerator $generator): int
    {
        $today = BusinessDate::today();
        $to = $today->copy()->addYear();
        $tomorrow = $today->copy()->addDay()->toDateString();

        $users = User::query()
            ->where('employment_status', 'active')
            ->where('paid_leave_auto_grant_enabled', true)
            ->whereNotNull('hire_date')
            ->get();

        $processedUsers = 0;
        $recalculatedEntries = 0;

        foreach ($users as $user) {
            try {
                $candidates = $generator->candidatesFor($user, $today, $to);

                $commandBus->dispatch(new RecalculateFutureSchedule(
                    userId: $user->id,
                    candidates: $candidates,
                    reason: '日次の再計算',
                    fromDate: $tomorrow,
                    overrideManualEdits: false,
                ));

                $processedUsers++;
                $recalculatedEntries += count($candidates);
            } catch (\Throwable $e) {
                // 1社員の失敗が他の社員のScheduleロールに影響しないようにする
                // (GenerateCompanyCalendarYearsHandlerの本体単位フェイルセーフと同じ考え方)。
                report($e);
            }
        }

        $this->info("{$processedUsers} 名の社員について、付与予定を再計算しました(候補 {$recalculatedEntries} 件を基に確認)。");

        return self::SUCCESS;
    }
}
