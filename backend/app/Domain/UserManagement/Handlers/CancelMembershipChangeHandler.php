<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\UserManagement\Aggregates\MembershipChangeSetAggregate;
use App\Domain\UserManagement\Commands\CancelMembershipChange;
use Illuminate\Support\Facades\DB;

/** @implements CommandHandler<CancelMembershipChange> */
class CancelMembershipChangeHandler implements CommandHandler
{
    public function handle(Command $command): string
    {
        assert($command instanceof CancelMembershipChange);
        $status = DB::table('membership_change_sets')->where('id', $command->changeSetId)->lockForUpdate()->value('status');
        if (! in_array($status, ['draft', 'scheduled'], true)) {
            throw new DomainRuleException('適用前の変更セットだけを取り消せます。');
        } MembershipChangeSetAggregate::retrieve($command->changeSetId)->cancel($command->actorUserId)->persist();

        return $command->changeSetId;
    }
}
