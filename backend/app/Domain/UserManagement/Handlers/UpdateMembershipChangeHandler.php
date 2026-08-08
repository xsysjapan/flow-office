<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\UserManagement\Aggregates\MembershipChangeSetAggregate;
use App\Domain\UserManagement\Commands\UpdateMembershipChange;
use App\Domain\UserManagement\Services\MembershipConstraintValidator;
use Illuminate\Support\Facades\DB;

/** @implements CommandHandler<UpdateMembershipChange> */ class UpdateMembershipChangeHandler implements CommandHandler
{
    public function __construct(private MembershipConstraintValidator $validator) {}

    public function handle(Command $c): string
    {
        assert($c instanceof UpdateMembershipChange);
        $status = DB::table('membership_change_sets')->where('id', $c->changeSetId)->lockForUpdate()->value('status');
        if (! in_array($status, ['draft', 'scheduled'], true)) {
            throw new DomainRuleException('適用前の変更セットだけを更新できます。');
        } $this->validator->validateItems($c->userId, $c->items);
        MembershipChangeSetAggregate::retrieve($c->changeSetId)->update($c->userId, $c->effectiveAt, $c->sourceType, $c->items, $c->note, $c->actorUserId)->persist();

        return $c->changeSetId;
    }
}
