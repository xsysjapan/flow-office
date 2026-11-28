<?php

namespace App\Domain\CompensatoryLeave\Services;

use App\Domain\CompensatoryLeave\Aggregates\CompensatoryLeaveRequestAggregate;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;

/**
 * 代休申請の承認の処理。承認のCommand(ApproveCompensatoryLeaveRequestHandler)と、承認不要の設定での直接の申請
 * (RequestCompensatoryLeaveHandlerが申請の記録の後に続けて承認を記録する)で共通に使う。
 *
 * - approvedByUserIdがnullの場合は「承認ワークフロー不要」設定による即時承認(承認者チェックを行わない)。
 * - viaReactor=true(ワークフローの承認・まとめ申請の兄弟承認からのReactor発行)では承認者チェックを行わず、
 *   既に承認済みなら何もしない(冪等)。
 */
class CompensatoryLeaveRequestApprover
{
    public function approve(string $compensatoryLeaveRequestId, ?string $approvedByUserId, bool $viaReactor): void
    {
        $aggregate = CompensatoryLeaveRequestAggregate::retrieve($compensatoryLeaveRequestId);

        if ($viaReactor && $aggregate->isApproved()) {
            return;
        }

        if (! $viaReactor
            && $approvedByUserId !== null
            && $aggregate->approverUserId() !== $approvedByUserId
        ) {
            throw new DomainRuleException('指定された承認者のみ承認できます。');
        }

        $aggregate->approve($approvedByUserId)->persist();
    }
}
