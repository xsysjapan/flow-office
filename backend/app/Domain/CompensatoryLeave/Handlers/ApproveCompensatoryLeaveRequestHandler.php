<?php

namespace App\Domain\CompensatoryLeave\Handlers;

use App\Domain\CompensatoryLeave\Aggregates\CompensatoryLeaveRequestAggregate;
use App\Domain\CompensatoryLeave\Commands\ApproveCompensatoryLeaveRequest;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;

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
    public function handle(Command $command): mixed
    {
        assert($command instanceof ApproveCompensatoryLeaveRequest);

        $aggregate = CompensatoryLeaveRequestAggregate::retrieve($command->compensatoryLeaveRequestId);

        if ($command->viaReactor && $aggregate->isApproved()) {
            return null;
        }

        if (! $command->viaReactor
            && $command->approvedByUserId !== null
            && $aggregate->approverUserId() !== $command->approvedByUserId
        ) {
            throw new DomainRuleException('指定された承認者のみ承認できます。');
        }

        $aggregate->approve($command->approvedByUserId)->persist();

        return null;
    }
}
