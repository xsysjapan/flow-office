<?php

namespace App\Domain\AccessControl\Handlers;

use App\Domain\AccessControl\Aggregates\RoleAssignmentAggregate;
use App\Domain\AccessControl\Commands\UpdateRoleAssignment;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use Illuminate\Support\Facades\DB;

/** @implements CommandHandler<UpdateRoleAssignment> */ class UpdateRoleAssignmentHandler implements CommandHandler
{
    public function handle(Command $c): string
    {
        assert($c instanceof UpdateRoleAssignment);
        if (! DB::table('role_assignments')->where('id', $c->assignmentId)->where('status', 'active')->exists()) {
            throw new DomainRuleException('有効なRoleAssignmentが存在しません。');
        } if ($c->scopeType === 'group' && ! $c->scopeGroupId) {
            throw new DomainRuleException('GROUPスコープでは対象Groupが必須です。');
        } if ($c->scopeType !== 'group' && $c->scopeGroupId) {
            throw new DomainRuleException('GROUP以外に対象Groupは指定できません。');
        } if ($c->scopeType !== 'group' && $c->includeDescendants) {
            throw new DomainRuleException('配下を含む指定はGROUPスコープだけで利用できます。');
        } if ($c->scopeGroupId && ! DB::table('groups')->where('id', $c->scopeGroupId)->where('status', 'active')->exists()) {
            throw new DomainRuleException('有効なスコープ対象Groupを指定してください。');
        } $roleId = DB::table('role_assignments')->where('id', $c->assignmentId)->value('role_id');
        if (! DB::table('permission_role')
            ->join('permission_scope_types', 'permission_scope_types.permission_id', '=', 'permission_role.permission_id')
            ->where('permission_role.role_id', $roleId)
            ->where('permission_scope_types.scope_type', $c->scopeType)
            ->exists()) {
            throw new DomainRuleException('このRoleには選択したスコープで有効になるPermissionがありません。');
        } if ($c->startsAt && $c->endsAt && $c->startsAt > $c->endsAt) {
            throw new DomainRuleException('開始日時は終了日時以前にしてください。');
        } RoleAssignmentAggregate::retrieve($c->assignmentId)->update($c->scopeType, $c->scopeGroupId, $c->includeDescendants, $c->startsAt, $c->endsAt, $c->actorUserId)->persist();

        return $c->assignmentId;
    }
}
