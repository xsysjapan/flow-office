<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeave\Commands\ApprovePaidLeaveRequest;
use App\Domain\PaidLeave\Services\PaidLeaveRequestApprover;
use App\Models\PaidLeaveRequest;

/**
 * UC-P004: 有給申請を承認する。有給申請の集約へ承認を記録するだけで、消化記録の確定(残数)は
 * `paid_leave_request.approved`を受けた有給口座のReactorが行う。
 *
 * - approvedByUserIdがnullの場合は「承認ワークフロー不要」設定による即時承認(承認者チェックを行わない)。
 * - viaReactor=true(ワークフローの承認・まとめ申請の兄弟承認からのReactor発行)では承認者チェックを行わず、
 *   既に承認済みなら何もしない(冪等)。
 * - 残数不足でも承認は拒否しない(論点17。部分充当は残数側の確定で行う)。
 *
 * @implements CommandHandler<ApprovePaidLeaveRequest>
 */
class ApprovePaidLeaveRequestHandler implements CommandHandler
{
    public function __construct(private readonly PaidLeaveRequestApprover $approver) {}

    public function handle(Command $command): PaidLeaveRequest
    {
        assert($command instanceof ApprovePaidLeaveRequest);

        return $this->approver->approve($command->paidLeaveRequestId, $command->approvedByUserId, $command->viaReactor);
    }
}
