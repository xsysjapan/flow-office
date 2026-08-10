<?php

namespace App\Domain\AccessControl\Handlers;

use App\Domain\AccessControl\Aggregates\GroupAccessAggregate;
use App\Domain\AccessControl\Commands\AssignFeatureToGroup;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** @implements CommandHandler<AssignFeatureToGroup> */
class AssignFeatureToGroupHandler implements CommandHandler
{
    public function handle(Command $command): string
    {
        assert($command instanceof AssignFeatureToGroup);
        if (! DB::table('groups')->where('id', $command->groupId)->where('status', 'active')->exists() || ! DB::table('features')->where('id', $command->featureId)->where('status', 'active')->exists()) {
            throw new DomainRuleException('有効なグループとFeatureを指定してください。');
        } if (DB::table('group_feature_assignments')->where('group_id', $command->groupId)->where('feature_id', $command->featureId)->exists()) {
            throw new DomainRuleException('Featureは既に付与されています。');
        } $aggregateId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'group-access:'.$command->groupId)->toString();
        GroupAccessAggregate::retrieve($aggregateId)->assignFeature($command->groupId, $command->featureId, $command->actorUserId)->persist();

        return $command->groupId;
    }
}
