<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeave\Commands\CancelPaidLeaveRequest;
use App\Domain\PaidLeave\Events\PaidLeaveRequestCancelled;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use Illuminate\Support\Carbon;

/**
 * @implements CommandHandler<CancelPaidLeaveRequest>
 */
class CancelPaidLeaveRequestHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

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

        $request->status = PaidLeaveRequestStatus::CANCELLED;
        $request->cancelled_at = Carbon::now();
        $request->save();

        $this->eventStore->append(
            aggregateType: 'paid_leave_request',
            aggregateId: (string) $request->id,
            event: new PaidLeaveRequestCancelled(
                paidLeaveRequestId: $request->id,
                cancelledByUserId: $command->cancelledByUserId,
            ),
        );

        return $request;
    }
}
