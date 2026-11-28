<?php

namespace App\Domain\PaidLeaveAccount\Handlers;

use App\Domain\EventSourcing\BulkItemRunner;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\ManualGrantId;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeaveToUsers;

/**
 * 利用者ごとに`GrantPaidLeave`(付与IDは`request_id`と利用者IDから決まる・冪等)を行い、1人でも失敗したら全体を戻す。
 *
 * @implements CommandHandler<GrantPaidLeaveToUsers>
 */
class GrantPaidLeaveToUsersHandler implements CommandHandler
{
    public function __construct(private readonly CommandBus $commandBus) {}

    /**
     * @return array{processed: list<int|string>, skipped: list<int|string>}
     */
    public function handle(Command $command): array
    {
        assert($command instanceof GrantPaidLeaveToUsers);

        return BulkItemRunner::run($command->userIds, function ($userId) use ($command) {
            $this->commandBus->dispatch(new GrantPaidLeave(
                userId: (string) $userId,
                grantedOn: $command->grantedOn,
                expiresOn: $command->expiresOn,
                grantedDays: $command->grantedDays,
                grantReason: $command->grantReason,
                grantId: ManualGrantId::for('paid_leave', $command->requestId, (string) $userId),
                idempotent: true,
            ));
        }, fn ($userId) => $command->userLabels[(string) $userId] ?? null);
    }
}
