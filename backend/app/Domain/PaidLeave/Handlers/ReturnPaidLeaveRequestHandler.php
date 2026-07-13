<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeave\Commands\ReturnPaidLeaveRequest;
use App\Domain\PaidLeave\Events\PaidLeaveRequestReturned;
use App\Jobs\SendTeamsNotificationJob;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use Illuminate\Support\Carbon;

/**
 * @implements CommandHandler<ReturnPaidLeaveRequest>
 */
class ReturnPaidLeaveRequestHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

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

        $request->status = PaidLeaveRequestStatus::RETURNED;
        $request->returned_at = Carbon::now();
        $request->save();

        $this->eventStore->append(
            aggregateType: 'paid_leave_request',
            aggregateId: (string) $request->id,
            event: new PaidLeaveRequestReturned(
                paidLeaveRequestId: $request->id,
                returnedByUserId: $command->returnedByUserId,
                comment: $command->comment,
            ),
        );

        SendTeamsNotificationJob::enqueue(
            title: '有給申請の差戻し',
            summary: "{$request->target_date->toDateString()} の有給申請が差し戻されました: {$command->comment}",
            detailUrl: null,
        );

        return $request;
    }
}
