<?php

namespace App\Domain\CompensatoryLeave\Handlers;

use App\Domain\CompensatoryLeave\Aggregates\CompensatoryLeaveRequestAggregate;
use App\Domain\CompensatoryLeave\Commands\ReturnCompensatoryLeaveRequest;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Jobs\SendNotificationJob;
use App\Models\CompensatoryLeaveRequest;
use App\Models\CompensatoryLeaveRequestStatus;
use App\Models\User;
use App\Support\FrontendUrl;

/**
 * @implements CommandHandler<ReturnCompensatoryLeaveRequest>
 */
class ReturnCompensatoryLeaveRequestHandler implements CommandHandler
{
    public function handle(Command $command): CompensatoryLeaveRequest
    {
        assert($command instanceof ReturnCompensatoryLeaveRequest);

        $request = CompensatoryLeaveRequest::query()->findOrFail($command->compensatoryLeaveRequestId);

        if ($request->status !== CompensatoryLeaveRequestStatus::SUBMITTED) {
            throw new DomainRuleException('提出済みの代休申請のみ差戻しできます。');
        }

        if ($request->approver_user_id !== $command->returnedByUserId) {
            throw new DomainRuleException('指定された承認者のみ差戻しできます。');
        }

        CompensatoryLeaveRequestAggregate::retrieve($request->id)
            ->returnRequest($command->returnedByUserId, $command->comment)
            ->persist();

        $request = $request->refresh();

        $applicant = User::find($request->user_id);
        if ($applicant !== null) {
            SendNotificationJob::enqueue(
                recipient: $applicant,
                title: '代休申請の差戻し',
                summary: "{$request->target_date->toDateString()} の代休申請が差し戻されました: {$command->comment}",
                detailUrl: FrontendUrl::path('/compensatory-leave/history'),
            );
        }

        return $request;
    }
}
