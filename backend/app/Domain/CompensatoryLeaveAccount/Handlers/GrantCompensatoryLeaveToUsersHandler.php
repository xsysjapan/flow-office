<?php

namespace App\Domain\CompensatoryLeaveAccount\Handlers;

use App\Domain\CompensatoryLeaveAccount\Commands\GrantCompensatoryLeave;
use App\Domain\CompensatoryLeaveAccount\Commands\GrantCompensatoryLeaveToUsers;
use App\Domain\EventSourcing\BulkItemRunner;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\ManualGrantId;

/**
 * 利用者ごとに`GrantCompensatoryLeave`(付与IDは`request_id`と利用者IDから決まる・冪等)を行い、1人でも失敗したら全体を戻す。
 *
 * @implements CommandHandler<GrantCompensatoryLeaveToUsers>
 */
class GrantCompensatoryLeaveToUsersHandler implements CommandHandler
{
    public function __construct(private readonly CommandBus $commandBus) {}

    /**
     * @return array{processed: list<int|string>, skipped: list<int|string>}
     */
    public function handle(Command $command): array
    {
        assert($command instanceof GrantCompensatoryLeaveToUsers);

        return BulkItemRunner::run($command->userIds, function ($userId) use ($command) {
            $this->commandBus->dispatch(new GrantCompensatoryLeave(
                userId: (string) $userId,
                workDate: $command->workDate,
                grantId: ManualGrantId::for('compensatory_leave', $command->requestId, (string) $userId),
                expiresOn: $command->expiresOn,
                grantReason: $command->grantReason,
                idempotent: true,
            ));
        }, fn ($userId) => $command->userLabels[(string) $userId] ?? null);
    }
}
