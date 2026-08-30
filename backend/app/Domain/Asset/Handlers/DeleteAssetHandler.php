<?php

namespace App\Domain\Asset\Handlers;

use App\Domain\Asset\Aggregates\AssetAggregate;
use App\Domain\Asset\Commands\DeleteAsset;
use App\Domain\Asset\Guards\AssetActiveBusinessGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\Asset;

/** @implements CommandHandler<DeleteAsset> */
class DeleteAssetHandler implements CommandHandler
{
    public function __construct(private readonly AssetActiveBusinessGuard $guard) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof DeleteAsset);

        $asset = Asset::query()->findOrFail($command->assetId);
        $this->guard->assertDeletable($asset);

        AssetAggregate::retrieve($command->assetId)
            ->delete(deletedByUserId: $command->deletedByUserId)
            ->persist();

        return null;
    }
}
