<?php

namespace App\Domain\PaidLeaveAccount\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveAccount\Commands\AllocateUnallocatedPaidLeaveUsages;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantCreated;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * 有給の付与の記録(paid_leave_account.grant_created)を受けて、その利用者の未充当の消化記録へ、確定の古い順に、
 * その付与の残りの範囲で充当する(論点19 決定10-1)。発行するCommandは viaReactor=true で、充当できるものが
 * 無ければ何もしない(冪等)。
 */
class UnallocatedUsageOnPaidLeaveGrantReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onPaidLeaveGrantCreated(PaidLeaveGrantCreated $event): void
    {
        $this->commandBus->dispatch(new AllocateUnallocatedPaidLeaveUsages(
            userId: $event->aggregateRootUuid(),
            grantId: $event->grantId,
            viaReactor: true,
            initiatedByUserId: null,
        ));
    }
}
