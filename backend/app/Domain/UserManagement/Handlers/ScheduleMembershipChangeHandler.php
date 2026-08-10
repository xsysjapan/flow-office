<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\UserManagement\Aggregates\MembershipChangeSetAggregate;
use App\Domain\UserManagement\Commands\ScheduleMembershipChange;
use App\Domain\UserManagement\Services\MembershipConstraintValidator;

/** @implements CommandHandler<ScheduleMembershipChange> */
class ScheduleMembershipChangeHandler implements CommandHandler
{
    public function __construct(private MembershipConstraintValidator $validator) {}

    public function handle(Command $c): string
    {
        assert($c instanceof ScheduleMembershipChange);
        if (! $c->items) {
            throw new DomainRuleException('変更明細が必要です。');
        } $this->validator->validateItems($c->userId, $c->items);
        MembershipChangeSetAggregate::retrieve($c->changeSetId)->schedule($c->userId, $c->effectiveAt, $c->sourceType, $c->items, $c->note, $c->actorUserId)->persist();

        return $c->changeSetId;
    }
}
