<?php

namespace App\Domain\CompensatoryLeave\Handlers;

use App\Domain\CompensatoryLeave\Commands\ApproveCompensatoryLeaveRequest;
use App\Domain\CompensatoryLeave\Services\CompensatoryLeaveRequestApprover;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;

/**
 * 代休申請を承認する。申請の集約へ承認を記録するだけで、消化記録の確定(残数)は
 * `compensatory_leave.request_approved`を受けた代休口座のReactorが、勤怠の再計算は勤怠のReactorが行う(原則15)。
 *
 * - approvedByUserIdがnullの場合は「承認ワークフロー不要」設定による即時承認(承認者チェックを行わない)。
 * - viaReactor=true(ワークフローの承認・まとめ申請の兄弟承認からのReactor発行)では承認者チェックを行わず、
 *   既に承認済みなら何もしない(冪等)。
 * - 残数不足でも承認は拒否しない(論点17。充当できた分だけ充当し、不足量は口座が記録する)。
 *
 * @implements CommandHandler<ApproveCompensatoryLeaveRequest>
 */
class ApproveCompensatoryLeaveRequestHandler implements CommandHandler
{
    public function __construct(private readonly CompensatoryLeaveRequestApprover $approver) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof ApproveCompensatoryLeaveRequest);

        $this->approver->approve($command->compensatoryLeaveRequestId, $command->approvedByUserId, $command->viaReactor);

        return null;
    }
}
