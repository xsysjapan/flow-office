<?php

namespace App\Jobs;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveSchedule\Commands\RecalculateFutureSchedule;
use App\Domain\PaidLeaveSchedule\Support\ScheduleCandidateGenerator;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * docs/changesets/20260916-paid-leave-policy-change-reapply/spec.md: 法定付与ポリシー
 * (`paid_leave_grant_policies`/`paid_leave_proportional_grant_policies`)・付与ルール
 * (`paid_leave_grant_rules`)が変更された際に、対象社員全員の未確定Scheduleを新しい内容へ
 * 追従させる。`RollPaidLeaveSchedulesCommand`と同じ対象社員条件・フェイルセーフパターンを
 * 踏襲するが、個別修正(`manuallyEditScheduleEntry`)済みエントリも再作成の対象に含める点が
 * 異なる(`overrideManualEdits: true`)。`Granted`/`Cancelled`の確定済みエントリは
 * `PaidLeaveScheduleAggregate::recalculateFutureSchedule()`側で常に対象外となる。
 *
 * XSERVER上は常駐workerを前提にしないため、DBキュー経由のJobとして実装する
 * (`docs/02-tech-stack.md`: cronから`schedule:run`→`queue:work --stop-when-empty`)。
 */
class ReapplyPaidLeaveSchedulePolicyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly string $reason) {}

    public function handle(CommandBus $commandBus, ScheduleCandidateGenerator $generator): void
    {
        $from = Carbon::today();
        $to = $from->copy()->addYear();

        $users = User::query()
            ->where('employment_status', 'active')
            ->where('paid_leave_auto_grant_enabled', true)
            ->whereNotNull('hire_date')
            ->get();

        foreach ($users as $user) {
            try {
                $candidates = $generator->candidatesFor($user, $from, $to);

                $commandBus->dispatch(new RecalculateFutureSchedule(
                    userId: $user->id,
                    candidates: $candidates,
                    reason: $this->reason,
                    overrideManualEdits: true,
                ));
            } catch (Throwable $e) {
                // 1社員の失敗が他の社員の再計算に影響しないようにする
                // (RollPaidLeaveSchedulesCommandと同じフェイルセーフパターン)。
                report($e);
            }
        }
    }
}
