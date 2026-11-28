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
        // Reactor発行(グループの初期ロール)は、割当の集約が作成済みなら何もしない(冪等)。
        if ($command->viaReactor && RoleAssignmentAggregate::retrieve($command->assignmentId)->isCreated()) {
            return $command->assignmentId;
        }
        if ($command->scopeType === 'group' && ! $command->scopeGroupId) {
            throw new DomainRuleException('GROUPスコープでは対象グループが必須です。');
        } if ($command->scopeType !== 'group' && $command->scopeGroupId) {
            throw new DomainRuleException('GROUP以外のスコープに対象グループは指定できません。');
        } if ($command->scopeType !== 'group' && $command->includesDescendants) {
            throw new DomainRuleException('配下を含む指定はGROUPスコープだけで利用できます。');
        } if ($command->scopeGroupId && ! DB::table('groups')->where('id', $command->scopeGroupId)->where('status', 'active')->exists()) {
            throw new DomainRuleException('有効なスコープ対象Groupを指定してください。');
        } if ($command->startsAt && $command->endsAt && $command->startsAt > $command->endsAt) {
            throw new DomainRuleException('開始日時は終了日時以前にしてください。');
        } if (! DB::table('roles')->where('id', $command->roleId)->where('status', 'active')->exists()) {
            throw new DomainRuleException('有効なRoleを指定してください。');
        } if (! DB::table('permission_role')
            ->join('permission_scope_types', 'permission_scope_types.permission_id', '=', 'permission_role.permission_id')
            ->where('permission_role.role_id', $command->roleId)
            ->where('permission_scope_types.scope_type', $command->scopeType)
            ->exists()) {
            throw new DomainRuleException('このRoleには選択したスコープで有効になるPermissionがありません。');
        } $exists = $command->subjectType === 'user' ? DB::table('users')->where('id', $command->subjectId)->exists() : DB::table('groups')->where('id', $command->subjectId)->where('status', 'active')->exists();
        if (! $exists) {
            throw new DomainRuleException('有効な付与先を指定してください。');
        } $this->privilegePolicy->assertSelfAssignmentAllowed($command->actorUserId, $command->subjectType, $command->subjectId, $command->roleId);
        RoleAssignmentAggregate::retrieve($command->assignmentId)->create($command->subjectType, $command->subjectId, $command->roleId, $command->scopeType, $command->scopeGroupId, $command->includesDescendants, $command->startsAt, $command->endsAt, $command->actorUserId)->persist();

        return $command->assignmentId;
    }
}
