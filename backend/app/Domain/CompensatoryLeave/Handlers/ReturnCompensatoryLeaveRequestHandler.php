<?php

namespace App\Domain\CompensatoryLeave\Handlers;

use App\Domain\CompensatoryLeave\Aggregates\CompensatoryLeaveRequestAggregate;
use App\Domain\CompensatoryLeave\Commands\ReturnCompensatoryLeaveRequest;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;

/**
 * 代休申請を差し戻す。申請の集約へ差戻しを記録するだけで、消化記録の取消(残数)と勤怠の解除は
 * `compensatory_leave.request_returned`を受ける各文脈のReactorが行う(原則15)。
 *
 * 差戻しの通知はワークフロー側が1回だけ送る(二重通知の解消。特別休暇と同じ)。
 * viaReactor=true で既に差戻し中なら何もしない(冪等)。
 *
 * @implements CommandHandler<ReturnCompensatoryLeaveRequest>
 */
class ReturnCompensatoryLeaveRequestHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof ReturnCompensatoryLeaveRequest);

        $aggregate = CompensatoryLeaveRequestAggregate::retrieve($command->compensatoryLeaveRequestId);

        if ($command->viaReactor && $aggregate->isReturned()) {
            return null;
        }

        if (! $command->viaReactor && $aggregate->approverUserId() !== $command->returnedByUserId) {
            throw new DomainRuleException('指定された承認者のみ差戻しできます。');
        }

        $aggregate->returnRequest($command->returnedByUserId, $command->comment)->persist();

        return null;
    }
}
