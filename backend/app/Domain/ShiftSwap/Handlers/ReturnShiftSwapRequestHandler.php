<?php

namespace App\Domain\ShiftSwap\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\ShiftSwap\Aggregates\ShiftSwapRequestAggregate;
use App\Domain\ShiftSwap\Commands\ReturnShiftSwapRequest;
use App\Jobs\SendNotificationJob;
use App\Models\ShiftSwapRequest;
use App\Models\ShiftSwapRequestStatus;
use App\Models\User;
use App\Support\FrontendUrl;

/**
 * @implements CommandHandler<ReturnShiftSwapRequest>
 */
class ReturnShiftSwapRequestHandler implements CommandHandler
{
    public function handle(Command $command): ShiftSwapRequest
    {
        assert($command instanceof ReturnShiftSwapRequest);

        $request = ShiftSwapRequest::query()->findOrFail($command->shiftSwapRequestId);

        if ($request->status !== ShiftSwapRequestStatus::SUBMITTED) {
            throw new DomainRuleException('提出済みの振替休日申請のみ差戻しできます。');
        }

        if ($request->approver_user_id !== $command->returnedByUserId) {
            throw new DomainRuleException('指定された承認者のみ差戻しできます。');
        }

        ShiftSwapRequestAggregate::retrieve($request->id)
            ->returnRequest($command->returnedByUserId, $command->comment)
            ->persist();

        $request = $request->refresh();

        $applicant = User::find($request->user_id);
        if ($applicant !== null) {
            SendNotificationJob::enqueue(
                recipient: $applicant,
                title: '振替休日申請の差戻し',
                summary: "{$request->target_date->toDateString()} の振替休日申請が差し戻されました: {$command->comment}",
                detailUrl: FrontendUrl::path('/shift-swap/history'),
            );
        }

        return $request;
    }
}
