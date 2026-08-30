<?php

namespace App\Domain\Asset\Handlers;

use App\Domain\Asset\Aggregates\AssetAggregate;
use App\Domain\Asset\Commands\UpdateAssetDetails;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\Asset;

/** @implements CommandHandler<UpdateAssetDetails> */
class UpdateAssetDetailsHandler implements CommandHandler
{
    public function handle(Command $command): Asset
    {
        assert($command instanceof UpdateAssetDetails);

        Asset::query()->findOrFail($command->assetId);

        AssetAggregate::retrieve($command->assetId)
            ->updateDetails(
                name: $command->name,
                category: $command->category,
                serialNumber: $command->serialNumber,
                notes: $command->notes,
                updatedByUserId: $command->updatedByUserId,
            )
            ->persist();

        return Asset::query()->findOrFail($command->assetId);
    }
}
