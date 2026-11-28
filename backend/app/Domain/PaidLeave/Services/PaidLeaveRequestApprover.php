<?php

namespace App\Domain\PaidLeave\Services;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeaveRequest\Aggregates\PaidLeaveRequestAggregate;
use App\Models\PaidLeaveRequest;

/**
 * 有給申請の承認の処理。承認のCommand(ApprovePaidLeaveRequestHandler)と、承認不要の設定での直接の申請
 * (RequestPaidLeaveHandlerが申請の記録の後に続けて承認を記録する)で共通に使う。
 *
 * - approvedByUserIdがnullの場合は「承認ワークフロー不要」設定による即時承認(承認者チェックを行わない)。
 * - viaReactor=true(ワークフローの承認・まとめ申請の兄弟承認からのReactor発行)では承認者チェックを行わず、
 *   既に承認済みなら何もしない(冪等)。
 */
class PaidLeaveRequestApprover
{
    public function approve(string $paidLeaveRequestId, ?string $approvedByUserId, bool $viaReactor): PaidLeaveRequest
    {
        $aggregate = PaidLeaveRequestAggregate::retrieve($paidLeaveRequestId);

        if ($viaReactor && $aggregate->isApproved()) {
            return PaidLeaveRequest::query()->findOrFail($paidLeaveRequestId);
        }

        if (! $viaReactor
            && $approvedByUserId !== null
            && $aggregate->approverUserId() !== $approvedByUserId
        ) {
            throw new DomainRuleException('指定された承認者のみ承認できます。');
        }

        $aggregate->approve($approvedByUserId)->persist();

        return PaidLeaveRequest::query()->findOrFail($paidLeaveRequestId);
    }
}
