<?php

namespace App\Domain\SpecialLeave\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\SpecialLeave\Commands\ApproveSpecialLeaveRequest;
use App\Domain\SpecialLeave\Services\SpecialLeaveRequestApprover;
use App\Models\SpecialLeaveRequest;

/**
 * 特別休暇申請を承認する。申請の集約へ承認を記録するだけで、消化記録の確定(残数)は
 * `special_leave.request_approved`を受けた特別休暇口座のReactorが、勤怠の再計算は勤怠のReactorが行う(原則15)。
 *
 * - approvedByUserIdがnullの場合は「承認ワークフロー不要」設定による即時承認(承認者チェックを行わない)。
 * - viaReactor=true(ワークフローの承認・まとめ申請の兄弟承認からのReactor発行)では承認者チェックを行わず、
 *   既に承認済みなら何もしない(冪等)。
 * - 残数不足でも承認は拒否しない(論点17。充当できた分だけ充当し、不足量は口座が記録する)。
 *
 * @implements CommandHandler<ApproveSpecialLeaveRequest>
 */
class ApproveSpecialLeaveRequestHandler implements CommandHandler
{
    public function __construct(private readonly SpecialLeaveRequestApprover $approver) {}

    public function handle(Command $command): SpecialLeaveRequest
    {
        assert($command instanceof ApproveSpecialLeaveRequest);

        return $this->approver->approve($command->specialLeaveRequestId, $command->approvedByUserId, $command->viaReactor);
    }
}
