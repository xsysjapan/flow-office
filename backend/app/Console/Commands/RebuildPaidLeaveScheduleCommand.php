<?php

namespace App\Console\Commands;

use App\Console\Attributes\AdminExecutable;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveSchedule\Commands\RecalculateFutureSchedule;
use App\Domain\PaidLeaveSchedule\Support\ScheduleCandidateGenerator;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * 過去の有給付与予定Schedule エントリを、現在の付与ルール・ポリシーに基づいて
 * 完全に削除・再作成するコマンド。データ不整合発見時の是正用。
 *
 * docs/changesets/20260919-paid-leave-schedule-rebuild/spec.md 仕様確定事項参照。
 * 対象社員・期間・ルールを指定して実行し、既存エントリを取消＆新規エントリで再作成する。
 * Account側`paid_leave_grants`(実際の付与)には一切影響しない。
 */
#[AdminExecutable(
    label: '有給休暇付与予定の再作成',
    rules: [
        'rule-id' => ['nullable', 'integer', 'exists:paid_leave_grant_rules,id'],
        'from' => ['nullable', 'date_format:Y-m-d'],
        'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        'reason' => ['required', 'string', 'max:255'],
    ],
    ui: [
        'rule-id' => ['control' => 'text'],
        'from' => ['control' => 'text'],
        'to' => ['control' => 'text'],
        'reason' => ['control' => 'text'],
    ],
)]
final class RebuildPaidLeaveScheduleCommand extends Command
{
    protected $signature = 'paid-leave:schedule:rebuild
        {--rule-id= : 対象の付与ルールID(省略可。未指定時は全ルール対象)}
        {--from= : 対象期間の開始日YYYY-MM-DD(省略可。未指定時は各社員の入社日)}
        {--to= : 対象期間の終了日YYYY-MM-DD(省略可。未指定時は今日+1年)}
        {--reason= : 取消イベントの理由(必須)}';

    protected $description = '過去の有給付与予定Scheduleエントリを現在のルール・ポリシーに基づいて再作成する';

    public function handle(CommandBus $commandBus, ScheduleCandidateGenerator $generator): int
    {
        $ruleId = $this->option('rule-id') ? (int) $this->option('rule-id') : null;
        $from = $this->option('from') ? Carbon::parse($this->option('from')) : null;
        $to = $this->option('to') ? Carbon::parse($this->option('to')) : null;
        $reason = $this->option('reason');

        // 対象社員の決定: RollPaidLeaveSchedulesCommand と同じ対象社員条件
        $users = User::query()
            ->where('employment_status', 'active')
            ->where('paid_leave_auto_grant_enabled', true)
            ->whereNotNull('hire_date')
            ->get();

        if ($ruleId !== null) {
            // ルールID指定時: そのルールに現在マッチする社員のみ
            $targetUsers = [];
            foreach ($users as $user) {
                $workStyle = $generator->currentWorkStyleFor($user);
                $matchingRule = $generator->matchingRuleFor($workStyle);

                if ($matchingRule && $matchingRule->id === $ruleId) {
                    $targetUsers[] = $user;
                }
            }
        } else {
            // ルールID未指定時: 対象社員条件を満たす全社員
            $targetUsers = $users->all();
        }

        $successCount = 0;
        $failureCount = 0;

        foreach ($targetUsers as $user) {
            try {
                $fromDate = $from ?? Carbon::parse($user->hire_date);
                $toDate = $to ?? Carbon::today()->addYear();

                $candidates = $generator->candidatesFor($user, $fromDate, $toDate);

                // 候補が無い場合でも command は発行する (既存エントリがあれば取消される)
                $commandBus->dispatch(new RecalculateFutureSchedule(
                    userId: $user->id,
                    candidates: $candidates,
                    reason: $reason,
                    overrideManualEdits: true,
                    includeGrantedEntries: true,
                ));

                $successCount++;
            } catch (\Throwable $e) {
                // 1社員の失敗が他の社員に影響しないようにする
                report($e);
                $failureCount++;
                $this->error("ユーザー [{$user->id}] の処理に失敗しました: {$e->getMessage()}");
            }
        }

        $this->info('有給付与予定の再作成が完了しました。');
        $this->info('対象社員: '.count($targetUsers).' 名');
        $this->info("成功: {$successCount} 名、失敗: {$failureCount} 名");

        return $failureCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
