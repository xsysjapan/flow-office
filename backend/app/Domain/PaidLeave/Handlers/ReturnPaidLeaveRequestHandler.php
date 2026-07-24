<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeave\Aggregates\PaidLeaveRequestAggregate;
use App\Domain\PaidLeave\Commands\ReturnPaidLeaveRequest;
use App\Jobs\SendNotificationJob;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use App\Models\User;

/**
 * @implements CommandHandler<ReturnPaidLeaveRequest>
 */
class ReturnPaidLeaveRequestHandler implements CommandHandler
{
    public function handle(Command $command): PaidLeaveRequest
    {
        assert($command instanceof ReturnPaidLeaveRequest);

        $request = PaidLeaveRequest::query()->findOrFail($command->paidLeaveRequestId);

        if ($request->status !== PaidLeaveRequestStatus::SUBMITTED) {
            throw new DomainRuleException('提出済みの有給申請のみ差戻しできます。');
        }

        if ($request->approver_user_id !== $command->returnedByUserId) {
            throw new DomainRuleException('指定された承認者のみ差戻しできます。');
        }

        PaidLeaveRequestAggregate::retrieve($request->id)
            ->returnRequest($command->returnedByUserId, $command->comment)
            ->persist();

        $request = $request->refresh();

        $applicant = User::find($request->user_id);
        if ($applicant !== null) {
            SendNotificationJob::enqueue(
                recipient: $applicant,
                title: '有給申請の差戻し',
                summary: "{$request->target_date->toDateString()} の有給申請が差し戻されました: {$command->comment}",
                detailUrl: null,
            );
        }

        return $request;
    }
}
