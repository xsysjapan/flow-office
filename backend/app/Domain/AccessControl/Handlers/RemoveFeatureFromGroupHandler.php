<?php

namespace App\Domain\AccessControl\Handlers;

use App\Domain\AccessControl\Aggregates\GroupAccessAggregate;
use App\Domain\AccessControl\Commands\RemoveFeatureFromGroup;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** @implements CommandHandler<RemoveFeatureFromGroup> */
class RemoveFeatureFromGroupHandler implements CommandHandler
{
    public function handle(Command $command): string
    {
        assert($command instanceof RemoveFeatureFromGroup);
        if (! DB::table('group_feature_assignments')->where('group_id', $command->groupId)->where('feature_id', $command->featureId)->exists()) {
            throw new DomainRuleException('Feature割当が存在しません。');
        } $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'group-access:'.$command->groupId)->toString();
        GroupAccessAggregate::retrieve($id)->removeFeature($command->groupId, $command->featureId, $command->actorUserId)->persist();

        return $command->groupId;
    }
}
