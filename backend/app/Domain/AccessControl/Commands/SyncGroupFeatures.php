<?php

namespace App\Domain\AccessControl\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/** グループへの有効なRole割当から導かれるFeatureの集合に、グループのFeature割当を差分で合わせる(差分が無ければ何もしない)。 */
class SyncGroupFeatures implements Command
{
    public function __construct(public readonly string $groupId, public readonly ?string $actorUserId = null) {}
}
