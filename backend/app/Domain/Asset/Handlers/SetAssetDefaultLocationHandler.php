<?php

namespace App\Domain\Asset\Handlers;

use App\Domain\Asset\Aggregates\AssetAggregate;
use App\Domain\Asset\Commands\SetAssetDefaultLocation;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Asset;
use App\Models\AssetManagementType;

/** @implements CommandHandler<SetAssetDefaultLocation> */
class SetAssetDefaultLocationHandler implements CommandHandler
{
    public function handle(Command $command): Asset
    {
        assert($command instanceof SetAssetDefaultLocation);

        $asset = Asset::query()->findOrFail($command->assetId);

        if ($asset->management_type !== AssetManagementType::LENDING) {
            throw new DomainRuleException('通常配置場所は貸出品にのみ設定できます。');
        }

        AssetAggregate::retrieve($command->assetId)
            ->setDefaultLocation(locationText: $command->locationText, setByUserId: $command->setByUserId)
            ->persist();

        return Asset::query()->findOrFail($command->assetId);
    }
}
