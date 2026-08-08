<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\UserManagement\Aggregates\MembershipChangeSetAggregate;
use App\Domain\UserManagement\Commands\ScheduleExistingMembershipChange;
use App\Domain\UserManagement\Services\MembershipConstraintValidator;
use Illuminate\Support\Facades\DB;

/** @implements CommandHandler<ScheduleExistingMembershipChange> */ class ScheduleExistingMembershipChangeHandler implements CommandHandler
{
    public function __construct(private MembershipConstraintValidator $validator) {}

    public function handle(Command $c): string
    {
        assert($c instanceof ScheduleExistingMembershipChange);
        $set = DB::table('membership_change_sets')->where('id', $c->changeSetId)->lockForUpdate()->first();
        if (! $set || $set->status !== 'draft') {
            throw new DomainRuleException('DRAFTだけを予約できます。');
        } $items = DB::table('membership_change_items')->where('change_set_id', $c->changeSetId)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $this->validator->validateItems($set->user_id, $items);
        MembershipChangeSetAggregate::retrieve($c->changeSetId)->schedule($set->user_id, $set->effective_at, $set->source_type, $items, $set->note, $c->actorUserId)->persist();

        return $c->changeSetId;
    }
}
