<?php

namespace App\Domain\PaidLeaveSchedule\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * hire_date/usage_start_date/work_style_id割当/独自ルール変更等をトリガーに、対象社員の
 * 未来Scheduleを再計算する。デフォルト(`$includeGrantedEntries=false`)では`Granted`/`Cancelled`の
 * 確定済みエントリと個別修正(`ManuallyEditScheduleEntry`済み)のエントリは対象外とする
 * (過去確定・個別修正の保護、依頼書§28)。`$includeGrantedEntries=true`の場合、`Cancelled`のみを
 * 対象外とし、`Granted`エントリも洗い替え対象に含める(過去分Schedule再作成コマンド用。
 * docs/changesets/20260919-paid-leave-schedule-rebuild/spec.md)。
 *
 * @phpstan-type CandidateEntry array{entryId: string, scheduledOn: string, category: string, candidateGrantDays: float}
 */
class RecalculateFutureSchedule implements Command
{
    /**
     * @param  array<int, CandidateEntry>  $candidates  最新条件で算出した「あるべき」Schedule一覧
     * @param  bool  $overrideManualEdits  trueの場合、個別修正済み(`manuallyEditScheduleEntry`実行済み)
     *                                     エントリも再作成の対象に含める(法定付与ポリシー・付与ルール変更トリガーのみtrueで発行する。
     *                                     docs/changesets/20260916-paid-leave-policy-change-reapply/spec.md)
     * @param  bool  $includeGrantedEntries  trueの場合、`Granted`エントリも洗い替え対象に含める
     *                                       (過去分Schedule再作成コマンド専用。Account側`paid_leave_grants`には影響しない)
     */
    public function __construct(
        public readonly string $userId,
        public readonly array $candidates,
        public readonly string $reason,
        public readonly bool $overrideManualEdits = false,
        public readonly bool $includeGrantedEntries = false,
    ) {}
}
