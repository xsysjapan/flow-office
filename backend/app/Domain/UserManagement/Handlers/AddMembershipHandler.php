<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\UserManagement\Aggregates\UserMembershipAggregate;
use App\Domain\UserManagement\Commands\AddMembership;
use App\Domain\UserManagement\Services\MembershipConstraintValidator;
use App\Domain\UserManagement\Support\UserManagementStreamId;
use Illuminate\Support\Facades\DB;

/** @implements CommandHandler<AddMembership> */
class AddMembershipHandler implements CommandHandler
{
    public function __construct(private MembershipConstraintValidator $validator) {}

    public function handle(Command $command): string
    {
        assert($command instanceof AddMembership);
        $group = DB::table('groups')->where('id', $command->groupId)->where('status', 'active')->first();
        if (! $group) {
            throw new DomainRuleException('有効なグループを指定してください。');
        } $state = $this->validator->current($command->userId);
        if (isset($state[$command->groupId])) {
            throw new DomainRuleException('既に所属しています。');
        } $state[$command->groupId] = ['group_id' => $command->groupId, 'group_type_id' => (int) $group->group_type_id, 'is_primary' => $command->isPrimary, 'membership_kind' => $command->membershipKind];
        $this->validator->validate($command->userId, $state);
        UserMembershipAggregate::retrieve(UserManagementStreamId::for('user-membership', $command->userId))->add($command->userId, $command->groupId, $command->membershipKind, $command->isPrimary, $command->actorUserId)->persist();

        return $command->userId;
    }
}
