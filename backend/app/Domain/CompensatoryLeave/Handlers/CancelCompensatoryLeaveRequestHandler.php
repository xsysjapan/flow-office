<?php

namespace App\Domain\CompensatoryLeave\Handlers;

use App\Domain\CompensatoryLeave\Aggregates\CompensatoryLeaveRequestAggregate;
use App\Domain\CompensatoryLeave\Commands\CancelCompensatoryLeaveRequest;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\CompensatoryLeaveRequest;
use App\Models\CompensatoryLeaveRequestStatus;

/**
 * @implements CommandHandler<CancelCompensatoryLeaveRequest>
 */
class CancelCompensatoryLeaveRequestHandler implements CommandHandler
{
    public function handle(Command $command): CompensatoryLeaveRequest
    {
        assert($command instanceof CancelCompensatoryLeaveRequest);

        $request = CompensatoryLeaveRequest::query()->findOrFail($command->compensatoryLeaveRequestId);

        if ($request->user_id !== $command->cancelledByUserId) {
            throw new DomainRuleException('自分の代休申請のみ取消できます。');
        }

        if ($request->status !== CompensatoryLeaveRequestStatus::SUBMITTED) {
            throw new DomainRuleException('提出済み(未承認)の代休申請のみ取消できます。');
        }

        CompensatoryLeaveRequestAggregate::retrieve($request->id)
            ->cancel($command->cancelledByUserId)
            ->persist();

        return $request->refresh();
    }
}
