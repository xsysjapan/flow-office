<?php

namespace App\Console\Commands;

use App\Console\Attributes\AdminExecutable;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveSchedule\Support\ScheduleCandidateGenerator;
use App\Jobs\ReapplyPaidLeaveSchedulePolicyJob;
use App\Models\PaidLeaveScheduleEntry;
use Illuminate\Console\Command;

/**
 * 全アクティブ社員の未確定の付与予定を、今の付与ルール・法定マスタ・年度開始日で作り直す。
 * `ReapplyPaidLeaveSchedulePolicyJob`と同じ処理を同期で行う(個別修正済みの行も作り直す。付与済み・取消済みは変えない)。
 *
 * デプロイ後(付与方式の初期値・日数の表の区分・一斉付与日の年度連動を入れた後)に実行する
 * (docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点8、docs/27-release-runbook.md)。
 * 作り直した社員の数と、付与予定日・日数(区分)が変わった行の数を出す。
 */
#[AdminExecutable(label: '有給付与予定のルール再適用')]
final class ReapplyPaidLeaveSchedulePolicyCommand extends Command
{
    protected $signature = 'paid-leave:schedule:reapply-policy';

    protected $description = '全アクティブ社員の未確定の有給付与予定を、今の付与ルール・年度開始日で作り直す';

    public function handle(CommandBus $commandBus, ScheduleCandidateGenerator $generator): int
    {
        $before = $this->activeEntryKeys();

        $processedUsers = (new ReapplyPaidLeaveSchedulePolicyJob('運用コマンドによる付与ルールの再適用'))
            ->reapplyAll($commandBus, $generator);

        $after = $this->activeEntryKeys();

        // 作り直しの前にあって、後には無い行(付与予定日・名目の月数・区分・日数のどれかが変わった行、または取り消された行)。
        $changedEntries = count(array_diff_key($before, $after));

        $this->info("作り直した社員: {$processedUsers} 名");
        $this->info("付与予定日・日数が変わった行: {$changedEntries} 件");

        return self::SUCCESS;
    }

    /**
     * 取消でない付与予定の行を、内容で見分けるキーの集合にする(キー => true)。
     *
     * @return array<string, true>
     */
    private function activeEntryKeys(): array
    {
        $keys = [];

        $entries = PaidLeaveScheduleEntry::query()
            ->where('status', '!=', PaidLeaveScheduleEntry::STATUS_CANCELLED)
            ->get(['id', 'user_id', 'scheduled_on', 'nominal_service_months', 'category', 'candidate_grant_days']);

        foreach ($entries as $entry) {
            $keys[$entry->id.'|'.$entry->scheduled_on->toDateString().'|'.$entry->nominal_service_months.'|'.$entry->category.'|'.$entry->candidate_grant_days] = true;
        }

        return $keys;
    }
}
