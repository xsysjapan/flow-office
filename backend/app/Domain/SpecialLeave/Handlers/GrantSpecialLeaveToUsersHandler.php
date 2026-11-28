<?php

namespace App\Domain\SpecialLeave\Handlers;

use App\Domain\EventSourcing\BulkItemRunner;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\ManualGrantId;
use App\Domain\SpecialLeave\Commands\GrantSpecialLeave;
use App\Domain\SpecialLeave\Commands\GrantSpecialLeaveToUsers;

/**
 * 利用者ごとに`GrantSpecialLeave`(付与IDは`request_id`と利用者IDから決まる・冪等)を行い、1人でも失敗したら全体を戻す。
 *
 * @implements CommandHandler<GrantSpecialLeaveToUsers>
 */
class GrantSpecialLeaveToUsersHandler implements CommandHandler
{
    public function __construct(private readonly CommandBus $commandBus) {}

    /**
     * @return array{processed: list<int|string>, skipped: list<int|string>}
     */
    public function handle(Command $command): array
    {
        assert($command instanceof GrantSpecialLeaveToUsers);

        return BulkItemRunner::run($command->userIds, function ($userId) use ($command) {
            $this->commandBus->dispatch(new GrantSpecialLeave(
                userId: (string) $userId,
                specialLeaveTypeId: $command->specialLeaveTypeId,
                grantedOn: $command->grantedOn,
                expiresOn: $command->expiresOn,
                grantedDays: $command->grantedDays,
                grantReason: $command->grantReason,
                grantId: ManualGrantId::for('special_leave', $command->requestId, (string) $userId),
                idempotent: true,
            ));
        }, fn ($userId) => $command->userLabels[(string) $userId] ?? null);
    }
}
