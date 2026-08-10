<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\UserManagement\Aggregates\MembershipChangeSetAggregate;
use App\Domain\UserManagement\Commands\FailMembershipChange;
use Illuminate\Support\Facades\DB;

/** @implements CommandHandler<FailMembershipChange> */ class FailMembershipChangeHandler implements CommandHandler
{
    public function handle(Command $c): string
    {
        assert($c instanceof FailMembershipChange);
        $status = DB::table('membership_change_sets')->where('id', $c->changeSetId)->lockForUpdate()->value('status');
        if ($status !== 'scheduled') {
            throw new DomainRuleException('予約中の変更セットだけを失敗にできます。');
        } MembershipChangeSetAggregate::retrieve($c->changeSetId)->markFailed($c->reason, $c->actorUserId)->persist();

        return $c->changeSetId;
    }
}
