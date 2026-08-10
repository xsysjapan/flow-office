<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\UserManagement\Aggregates\MembershipChangeSetAggregate;
use App\Domain\UserManagement\Aggregates\UserMembershipAggregate;
use App\Domain\UserManagement\Commands\ApplyMembershipChange;
use App\Domain\UserManagement\Services\MembershipConstraintValidator;
use App\Domain\UserManagement\Support\UserManagementStreamId;
use Illuminate\Support\Facades\DB;

/** @implements CommandHandler<ApplyMembershipChange> */
class ApplyMembershipChangeHandler implements CommandHandler
{
    public function __construct(private MembershipConstraintValidator $validator) {}

    public function handle(Command $command): string
    {
        assert($command instanceof ApplyMembershipChange);
        $set = DB::table('membership_change_sets')->where('id', $command->changeSetId)->lockForUpdate()->first();
        if (! $set || $set->status !== 'scheduled') {
            throw new DomainRuleException('適用可能な変更予約ではありません。');
        } DB::table('users')->where('id', $set->user_id)->lockForUpdate()->first();
        $items = DB::table('membership_change_items')->where('change_set_id', $command->changeSetId)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $this->validator->validateItems($set->user_id, $items);
        $aggregate = UserMembershipAggregate::retrieve(UserManagementStreamId::for('user-membership', $set->user_id));
        foreach ($items as $item) {
            $operation = strtolower($item['operation']);
            $from = $item['from_group_id'] ?? $item['target_group_id'] ?? null;
            if (in_array($operation, ['remove', 'replace'], true) && $from) {
                $aggregate->remove($set->user_id, $from, $command->actorUserId);
            }
        }
        foreach ($items as $item) {
            $operation = strtolower($item['operation']);
            $to = $item['to_group_id'] ?? $item['target_group_id'] ?? null;
            if (in_array($operation, ['add', 'replace'], true) && $to) {
                $aggregate->add($set->user_id, $to, ($item['is_primary'] ?? false) ? 'primary' : 'member', (bool) ($item['is_primary'] ?? false), $command->actorUserId);
            }
        }
        foreach ($items as $item) {
            if (strtolower($item['operation']) !== 'set_primary') {
                continue;
            } $target = $item['target_group_id'] ?? $item['from_group_id'] ?? null;
            if ($target) {
                $aggregate->changePrimary($set->user_id, $target, (bool) ($item['is_primary'] ?? true), $command->actorUserId);
            }
        }
        $aggregate->persist();
        MembershipChangeSetAggregate::retrieve($command->changeSetId)->markApplied($set->user_id, $items, $command->actorUserId)->persist();

        return $command->changeSetId;
    }
}
