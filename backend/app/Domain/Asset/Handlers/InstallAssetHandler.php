<?php

namespace App\Domain\Asset\Handlers;

use App\Domain\Asset\Aggregates\AssetAggregate;
use App\Domain\Asset\Commands\InstallAsset;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Asset;
use App\Models\AssetInstallationStatus;
use App\Models\AssetManagementType;
use Illuminate\Support\Carbon;

/** @implements CommandHandler<InstallAsset> */
class InstallAssetHandler implements CommandHandler
{
    public function handle(Command $command): Asset
    {
        assert($command instanceof InstallAsset);

        $asset = Asset::query()->findOrFail($command->assetId);

        if ($asset->management_type !== AssetManagementType::INSTALLATION) {
            throw new DomainRuleException('設置品以外は設置できません。');
        }

        if ($asset->installation_status !== AssetInstallationStatus::STORED) {
            throw new DomainRuleException('保管中の備品のみ設置できます。');
        }

        AssetAggregate::retrieve($command->assetId)
            ->install(locationText: $command->locationText, installedByUserId: $command->installedByUserId, installedAt: Carbon::now()->toIso8601String())
            ->persist();

        return Asset::query()->findOrFail($command->assetId);
    }
}
