<?php

namespace App\Domain\AccessControl\Handlers;

use App\Domain\AccessControl\Aggregates\RoleAssignmentAggregate;
use App\Domain\AccessControl\Commands\CreateRoleAssignment;
use App\Domain\AccessControl\Services\PrivilegeAssignmentPolicy;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use Illuminate\Support\Facades\DB;

/** @implements CommandHandler<CreateRoleAssignment> */
class CreateRoleAssignmentHandler implements CommandHandler
{
    public function __construct(private PrivilegeAssignmentPolicy $privilegePolicy) {}

    public function handle(Command $command): string
    {
        assert($command instanceof CreateRoleAssignment);
        if ($command->scopeType === 'group' && ! $command->scopeGroupId) {
            throw new DomainRuleException('GROUPスコープでは対象グループが必須です。');
        } if ($command->scopeType !== 'group' && $command->scopeGroupId) {
            throw new DomainRuleException('GROUP以外のスコープに対象グループは指定できません。');
        } if ($command->scopeType !== 'group' && $command->includeDescendants) {
            throw new DomainRuleException('配下を含む指定はGROUPスコープだけで利用できます。');
        } if ($command->scopeGroupId && ! DB::table('groups')->where('id', $command->scopeGroupId)->where('status', 'active')->exists()) {
            throw new DomainRuleException('有効なスコープ対象Groupを指定してください。');
        } if ($command->startsAt && $command->endsAt && $command->startsAt > $command->endsAt) {
            throw new DomainRuleException('開始日時は終了日時以前にしてください。');
        } if (! DB::table('roles')->where('id', $command->roleId)->where('status', 'active')->exists()) {
            throw new DomainRuleException('有効なRoleを指定してください。');
        } $exists = $command->subjectType === 'user' ? DB::table('users')->where('id', $command->subjectId)->exists() : DB::table('groups')->where('id', $command->subjectId)->where('status', 'active')->exists();
        if (! $exists) {
            throw new DomainRuleException('有効な付与先を指定してください。');
        } $this->privilegePolicy->assertSelfAssignmentAllowed($command->actorUserId, $command->subjectType, $command->subjectId, $command->roleId);
        RoleAssignmentAggregate::retrieve($command->assignmentId)->create($command->subjectType, $command->subjectId, $command->roleId, $command->scopeType, $command->scopeGroupId, $command->includeDescendants, $command->startsAt, $command->endsAt, $command->actorUserId)->persist();

        return $command->assignmentId;
    }
}
