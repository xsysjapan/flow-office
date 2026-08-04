<?php

namespace App\Domain\ShiftSwap\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\ShiftSwap\Aggregates\ShiftSwapRequestAggregate;
use App\Domain\ShiftSwap\Commands\CancelShiftSwapRequest;
use App\Models\ShiftSwapRequest;
use App\Models\ShiftSwapRequestStatus;

/**
 * @implements CommandHandler<CancelShiftSwapRequest>
 */
class CancelShiftSwapRequestHandler implements CommandHandler
{
    public function handle(Command $command): ShiftSwapRequest
    {
        assert($command instanceof CancelShiftSwapRequest);

        $request = ShiftSwapRequest::query()->findOrFail($command->shiftSwapRequestId);

        if ($request->user_id !== $command->cancelledByUserId) {
            throw new DomainRuleException('自分の振替休日申請のみ取消できます。');
        }

        if ($request->status !== ShiftSwapRequestStatus::SUBMITTED) {
            throw new DomainRuleException('提出済み(未承認)の振替休日申請のみ取消できます。');
        }

        ShiftSwapRequestAggregate::retrieve($request->id)
            ->cancel($command->cancelledByUserId)
            ->persist();

        return $request->refresh();
    }
}
