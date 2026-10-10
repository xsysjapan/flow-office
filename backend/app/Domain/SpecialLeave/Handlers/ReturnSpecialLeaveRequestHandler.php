<?php

namespace App\Domain\SpecialLeave\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\SpecialLeave\Aggregates\SpecialLeaveRequestAggregate;
use App\Domain\SpecialLeave\Commands\ReturnSpecialLeaveRequest;
use App\Models\SpecialLeaveRequest;

/**
 * 特別休暇申請を差し戻す。申請の集約へ差戻しを記録するだけで、消化記録の取消(残数)と勤怠の解除は
 * `special_leave.request_returned`を受ける各文脈のReactorが行う(原則15)。
 *
 * 差戻しの通知はワークフロー側(ReturnWorkflowRequestHandler)が1回だけ送る(二重通知の解消。有給と同じ)。
 * viaReactor=true で既に差戻し中なら何もしない(冪等)。
 *
 * @implements CommandHandler<ReturnSpecialLeaveRequest>
 */
class ReturnSpecialLeaveRequestHandler implements CommandHandler
{
    public function handle(Command $command): SpecialLeaveRequest
    {
        assert($command instanceof ReturnSpecialLeaveRequest);

        $aggregate = SpecialLeaveRequestAggregate::retrieve($command->specialLeaveRequestId);

        if ($command->viaReactor && $aggregate->isReturned()) {
            return SpecialLeaveRequest::query()->findOrFail($command->specialLeaveRequestId);
        }

        if (! $command->viaReactor && $aggregate->approverUserId() !== $command->returnedByUserId) {
            throw new DomainRuleException('指定された承認者のみ差戻しできます。');
        }

        $aggregate->returnRequest($command->returnedByUserId, $command->comment)->persist();

        return SpecialLeaveRequest::query()->findOrFail($command->specialLeaveRequestId);
    }
}
