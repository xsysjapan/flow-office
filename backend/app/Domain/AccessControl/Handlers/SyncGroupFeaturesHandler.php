<?php

namespace App\Domain\AccessControl\Handlers;

use App\Domain\AccessControl\Aggregates\GroupAccessAggregate;
use App\Domain\AccessControl\Commands\SyncGroupFeatures;
use App\Domain\AccessControl\Services\GroupFeatureSyncService;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use Ramsey\Uuid\Uuid;

/**
 * グループのFeature割当を、有効なRole割当から導かれる集合に合わせる。差分の計算はGroupFeatureSyncService、
 * 記録はグループのFeature集約へ1回で行う。差分が無ければ何も記録しない(同じ入力の再実行で変わらない)。
 *
 * @implements CommandHandler<SyncGroupFeatures>
 */
class SyncGroupFeaturesHandler implements CommandHandler
{
    public function __construct(private readonly GroupFeatureSyncService $diffCalculator) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof SyncGroupFeatures);

        $diff = $this->diffCalculator->diffForGroup($command->groupId);
        if ($diff['add'] === [] && $diff['remove'] === []) {
            return $command->groupId;
        }

        $aggregate = GroupAccessAggregate::retrieve(Uuid::uuid5(Uuid::NAMESPACE_URL, 'group-access:'.$command->groupId)->toString());
        foreach ($diff['add'] as $featureId) {
            $aggregate->assignFeature($command->groupId, $featureId, $command->actorUserId);
        }
        foreach ($diff['remove'] as $featureId) {
            $aggregate->removeFeature($command->groupId, $featureId, $command->actorUserId);
        }
        $aggregate->persist();

        return $command->groupId;
    }
}
