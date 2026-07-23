<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeave\Aggregates\PaidLeaveRequestAggregate;
use App\Domain\PaidLeave\Commands\CancelPaidLeaveRequest;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;

/**
 * @implements CommandHandler<CancelPaidLeaveRequest>
 */
class CancelPaidLeaveRequestHandler implements CommandHandler
{
    public function handle(Command $command): PaidLeaveRequest
    {
        assert($command instanceof CancelPaidLeaveRequest);

        $request = PaidLeaveRequest::query()->findOrFail($command->paidLeaveRequestId);

        if ($request->user_id !== $command->cancelledByUserId) {
            throw new DomainRuleException('自分の有給申請のみ取消できます。');
        }

        if ($request->status !== PaidLeaveRequestStatus::SUBMITTED) {
            throw new DomainRuleException('提出済み(未承認)の有給申請のみ取消できます。');
        }

        PaidLeaveRequestAggregate::retrieve($request->id)
            ->cancel($command->cancelledByUserId)
            ->persist();

        return $request->refresh();
    }
}
