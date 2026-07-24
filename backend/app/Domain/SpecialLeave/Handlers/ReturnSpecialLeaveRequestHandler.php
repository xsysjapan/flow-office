<?php

namespace App\Domain\SpecialLeave\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\SpecialLeave\Aggregates\SpecialLeaveRequestAggregate;
use App\Domain\SpecialLeave\Commands\ReturnSpecialLeaveRequest;
use App\Jobs\SendNotificationJob;
use App\Models\SpecialLeaveRequest;
use App\Models\SpecialLeaveRequestStatus;
use App\Models\User;

/**
 * @implements CommandHandler<ReturnSpecialLeaveRequest>
 */
class ReturnSpecialLeaveRequestHandler implements CommandHandler
{
    public function handle(Command $command): SpecialLeaveRequest
    {
        assert($command instanceof ReturnSpecialLeaveRequest);

        $request = SpecialLeaveRequest::query()->findOrFail($command->specialLeaveRequestId);

        if ($request->status !== SpecialLeaveRequestStatus::SUBMITTED) {
            throw new DomainRuleException('提出済みの特別休暇申請のみ差戻しできます。');
        }

        if ($request->approver_user_id !== $command->returnedByUserId) {
            throw new DomainRuleException('指定された承認者のみ差戻しできます。');
        }

        SpecialLeaveRequestAggregate::retrieve($request->id)
            ->returnRequest($command->returnedByUserId, $command->comment)
            ->persist();

        $request = $request->refresh();

        $applicant = User::find($request->user_id);
        if ($applicant !== null) {
            SendNotificationJob::enqueue(
                recipient: $applicant,
                title: '特別休暇申請の差戻し',
                summary: "{$request->target_date->toDateString()} の特別休暇申請が差し戻されました: {$command->comment}",
                detailUrl: null,
            );
        }

        return $request;
    }
}
