<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\UserManagement\Aggregates\UserMembershipAggregate;
use App\Domain\UserManagement\Commands\RemoveMembership;
use App\Domain\UserManagement\Services\MembershipConstraintValidator;
use App\Domain\UserManagement\Support\UserManagementStreamId;
use Illuminate\Support\Facades\DB;

/** @implements CommandHandler<RemoveMembership> */
class RemoveMembershipHandler implements CommandHandler
{
    public function __construct(private MembershipConstraintValidator $validator) {}

    public function handle(Command $command): string
    {
        assert($command instanceof RemoveMembership);
        $state = $this->validator->current($command->userId);
        if (! isset($state[$command->groupId])) {
            throw new DomainRuleException('所属が存在しません。');
        } $group = DB::table('groups')->where('id', $command->groupId)->first();
        $targetIsActive = DB::table('users')->where('id', $command->userId)->whereIn('account_status', ['active', 'leave'])->exists();
        $activeAdministrators = DB::table('memberships')->join('users', 'memberships.user_id', '=', 'users.id')->where('memberships.group_id', $command->groupId)->whereIn('users.account_status', ['active', 'leave'])->count();
        if ($group?->code === 'SYSTEM_ADMINISTRATORS' && $targetIsActive && $activeAdministrators <= 1) {
            throw new DomainRuleException('最後のシステム管理者は削除できません。');
        } unset($state[$command->groupId]);
        $this->validator->validate($command->userId, $state, [(int) $group->group_type_id]);
        UserMembershipAggregate::retrieve(UserManagementStreamId::for('user-membership', $command->userId))->remove($command->userId, $command->groupId, $command->actorUserId)->persist();

        return $command->userId;
    }
}
