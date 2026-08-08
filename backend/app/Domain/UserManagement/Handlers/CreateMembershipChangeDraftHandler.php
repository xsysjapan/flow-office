<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\UserManagement\Aggregates\MembershipChangeSetAggregate;
use App\Domain\UserManagement\Commands\CreateMembershipChangeDraft;
use App\Domain\UserManagement\Services\MembershipConstraintValidator;

/** @implements CommandHandler<CreateMembershipChangeDraft> */ class CreateMembershipChangeDraftHandler implements CommandHandler
{
    public function __construct(private MembershipConstraintValidator $validator) {}

    public function handle(Command $c): string
    {
        assert($c instanceof CreateMembershipChangeDraft);
        $this->validator->validateItems($c->userId, $c->items);
        MembershipChangeSetAggregate::retrieve($c->changeSetId)->create($c->userId, $c->effectiveAt, $c->sourceType, $c->items, $c->note, $c->actorUserId)->persist();

        return $c->changeSetId;
    }
}
