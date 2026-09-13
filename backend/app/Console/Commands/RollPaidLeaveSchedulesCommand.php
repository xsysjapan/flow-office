<?php

namespace App\Console\Commands;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveSchedule\Commands\EnsureFutureScheduleGenerated;
use App\Domain\PaidLeaveSchedule\Support\ScheduleCandidateGenerator;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * spec.md 論点6・実装対象Phase C: 全アクティブ社員について「現在から1年先まで
 * Scheduleエントリが存在すること」を保証する日次cronコマンド。`calendar:generate-years`
 * (`GenerateCompanyCalendarYearsCommand`)と同じ「日次cron・べき等・先行生成」の
 * 設計パターンを踏襲する。
 *
 * べき等性は`PaidLeaveScheduleAggregate::ensureFutureScheduleGenerated()`側
 * (`scheduledOn`が既存の未取消エントリと一致すればスキップ)で保証されるため、
 * このコマンドは対象期間の候補一式を毎回計算して渡すだけでよい
 * (無い月だけが実際にイベントとして追記される)。
 *
 * 旧`paid-leave:grant-scheduled`(`GrantScheduledPaidLeaveCommand`)を置換する
 * (spec.md 論点10)。
 */
class RollPaidLeaveSchedulesCommand extends Command
{
    protected $signature = 'paid-leave:roll-schedules';

    protected $description = '全アクティブ社員について、1年先までの有給付与予定Scheduleエントリの存在を保証する';

    public function handle(CommandBus $commandBus, ScheduleCandidateGenerator $generator): int
    {
        $from = Carbon::today();
        $to = $from->copy()->addYear();

        $users = User::query()
            ->where('employment_status', 'active')
            ->whereNotNull('hire_date')
            ->get();

        $processedUsers = 0;
        $createdEntries = 0;

        foreach ($users as $user) {
            try {
                $candidates = $generator->candidatesFor($user, $from, $to);

                if ($candidates === []) {
                    continue;
                }

                $commandBus->dispatch(new EnsureFutureScheduleGenerated(
                    userId: $user->id,
                    candidates: $candidates,
                ));

                $processedUsers++;
                $createdEntries += count($candidates);
            } catch (\Throwable $e) {
                // 1社員の失敗が他の社員のScheduleロールに影響しないようにする
                // (GenerateCompanyCalendarYearsHandlerの本体単位フェイルセーフと同じ考え方)。
                report($e);
            }
        }

        $this->info("{$processedUsers} 名の社員について、Scheduleエントリ候補 {$createdEntries} 件を確認・生成しました。");

        return self::SUCCESS;
    }
}
