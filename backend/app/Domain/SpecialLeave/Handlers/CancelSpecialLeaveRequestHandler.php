<?php

namespace App\Domain\SpecialLeave\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\SpecialLeave\Commands\CancelSpecialLeaveRequest;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestCancelled;
use App\Models\SpecialLeaveRequest;
use App\Models\SpecialLeaveRequestStatus;
use Illuminate\Support\Carbon;

/**
 * @implements CommandHandler<CancelSpecialLeaveRequest>
 */
class CancelSpecialLeaveRequestHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): SpecialLeaveRequest
    {
        assert($command instanceof CancelSpecialLeaveRequest);

        $request = SpecialLeaveRequest::query()->findOrFail($command->specialLeaveRequestId);

        if ($request->user_id !== $command->cancelledByUserId) {
            throw new DomainRuleException('自分の特別休暇申請のみ取消できます。');
        }

        if ($request->status !== SpecialLeaveRequestStatus::SUBMITTED) {
            throw new DomainRuleException('提出済み(未承認)の特別休暇申請のみ取消できます。');
        }

        $request->status = SpecialLeaveRequestStatus::CANCELLED;
        $request->cancelled_at = Carbon::now();
        $request->save();

        $this->eventStore->append(
            aggregateType: 'special_leave_request',
            aggregateId: (string) $request->id,
            event: new SpecialLeaveRequestCancelled(
                specialLeaveRequestId: $request->id,
                cancelledByUserId: $command->cancelledByUserId,
            ),
        );

        return $request;
    }
}
