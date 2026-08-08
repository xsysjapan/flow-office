<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\UserManagement\Aggregates\GroupTypeAggregate;
use App\Domain\UserManagement\Commands\UpdateGroupType;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** @implements CommandHandler<UpdateGroupType> */ class UpdateGroupTypeHandler implements CommandHandler
{
    public function handle(Command $c): string
    {
        assert($c instanceof UpdateGroupType);
        $type = DB::table('group_types')->where('id', $c->groupTypeId)->lockForUpdate()->first();
        if (! $type) {
            throw new DomainRuleException('GroupTypeが存在しません。');
        } if ($type->is_system && ($c->status !== 'active' || $c->membershipLimitType !== $type->membership_limit_type || $c->maxMembershipsPerUser !== ($type->max_memberships_per_user === null ? null : (int) $type->max_memberships_per_user) || $c->primaryMembershipRequired !== (bool) $type->primary_membership_required || $c->maxPrimaryMemberships !== ($type->max_primary_memberships === null ? null : (int) $type->max_primary_memberships))) {
            throw new DomainRuleException('システムGroupTypeは名称と表示順以外を変更できません。');
        } if ($c->membershipLimitType === 'limited' && $c->maxMembershipsPerUser === null) {
            throw new DomainRuleException('上限ありの場合は所属上限が必要です。');
        } if ($c->primaryMembershipRequired && ($c->maxPrimaryMemberships ?? 0) < 1) {
            throw new DomainRuleException('主所属必須の場合は主所属上限を1以上にしてください。');
        } if ($c->status === 'inactive' && DB::table('groups')->where('group_type_id', $c->groupTypeId)->where('status', 'active')->exists()) {
            throw new DomainRuleException('有効なGroupが残るGroupTypeは廃止できません。');
        } $counts = DB::table('memberships')->join('groups', 'memberships.group_id', '=', 'groups.id')->where('groups.group_type_id', $c->groupTypeId)->groupBy('memberships.user_id')->selectRaw('memberships.user_id, count(*) as membership_count, sum(case when memberships.is_primary = 1 then 1 else 0 end) as primary_count')->get();
        foreach ($counts as $count) {
            if ($c->maxMembershipsPerUser !== null && (int) $count->membership_count > $c->maxMembershipsPerUser) {
                throw new DomainRuleException('現在の所属が新しい所属上限を超えています。');
            } if ($c->primaryMembershipRequired && (int) $count->primary_count < 1) {
                throw new DomainRuleException('現在の所属に主所属がないため制約を変更できません。');
            } if ($c->maxPrimaryMemberships !== null && (int) $count->primary_count > $c->maxPrimaryMemberships) {
                throw new DomainRuleException('現在の主所属数が新しい上限を超えています。');
            }
        } GroupTypeAggregate::retrieve(Uuid::uuid5(Uuid::NAMESPACE_URL, 'group-type:'.$type->code)->toString())->update($c->groupTypeId, $c->name, $c->displayOrder, $c->status, $c->membershipLimitType, $c->maxMembershipsPerUser, $c->primaryMembershipRequired, $c->maxPrimaryMemberships, $c->actorUserId)->persist();

        return (string) $c->groupTypeId;
    }
}
