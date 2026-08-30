<?php

namespace App\Domain\AccessControl\Handlers;

use App\Domain\AccessControl\Aggregates\RoleAggregate;
use App\Domain\AccessControl\Commands\ChangeRoleFeatures;
use App\Domain\AccessControl\Services\GroupFeatureSyncService;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** @implements CommandHandler<ChangeRoleFeatures> */
class ChangeRoleFeaturesHandler implements CommandHandler
{
    public function __construct(private readonly GroupFeatureSyncService $groupFeatureSync) {}

    public function handle(Command $c): string
    {
        assert($c instanceof ChangeRoleFeatures);
        $role = DB::table('roles')->where('id', $c->roleId)->where('status', 'active')->first();
        if (! $role) {
            throw new DomainRuleException('有効なRoleが存在しません。');
        } if (DB::table('features')->whereIn('id', $c->featureIds)->count() !== count(array_unique($c->featureIds))) {
            throw new DomainRuleException('存在しないFeatureが含まれています。');
        } $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'role:'.$role->code)->toString();
        RoleAggregate::retrieve($id)->changeFeatures($c->roleId, array_values(array_unique($c->featureIds)), $c->actorUserId)->persist();

        $this->groupFeatureSync->syncGroupsForRole($c->roleId, $c->actorUserId);

        return (string) $c->roleId;
    }
}
