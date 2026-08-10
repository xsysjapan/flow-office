<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\UserManagement\Aggregates\GroupTypeAggregate;
use App\Domain\UserManagement\Commands\CreateGroupType;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** @implements CommandHandler<CreateGroupType> */ class CreateGroupTypeHandler implements CommandHandler
{
    public function handle(Command $c): string
    {
        assert($c instanceof CreateGroupType);
        if (DB::table('group_types')->where('code', $c->code)->exists()) {
            throw new DomainRuleException('GroupTypeコードは既に使用されています。');
        } if ($c->membershipLimitType === 'limited' && $c->maxMembershipsPerUser === null) {
            throw new DomainRuleException('上限ありの場合は所属上限が必要です。');
        } if ($c->primaryMembershipRequired && ($c->maxPrimaryMemberships ?? 0) < 1) {
            throw new DomainRuleException('主所属必須の場合は主所属上限を1以上にしてください。');
        } $businessId = (int) (DB::table('group_types')->lockForUpdate()->max('id') ?? 0) + 1;
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'group-type:'.$c->code)->toString();
        GroupTypeAggregate::retrieve($id)->create($businessId, $c->code, $c->name, $c->displayOrder, $c->membershipLimitType, $c->maxMembershipsPerUser, $c->primaryMembershipRequired, $c->maxPrimaryMemberships, $c->actorUserId)->persist();

        return $c->code;
    }
}
