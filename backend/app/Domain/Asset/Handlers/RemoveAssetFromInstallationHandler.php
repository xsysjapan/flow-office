<?php

namespace App\Domain\Asset\Handlers;

use App\Domain\Asset\Aggregates\AssetAggregate;
use App\Domain\Asset\Commands\RemoveAssetFromInstallation;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Asset;
use App\Models\AssetInstallationStatus;
use Illuminate\Support\Carbon;

/** @implements CommandHandler<RemoveAssetFromInstallation> */
class RemoveAssetFromInstallationHandler implements CommandHandler
{
    public function handle(Command $command): Asset
    {
        assert($command instanceof RemoveAssetFromInstallation);

        $asset = Asset::query()->findOrFail($command->assetId);

        if ($asset->installation_status !== AssetInstallationStatus::INSTALLED) {
            throw new DomainRuleException('設置中の備品のみ撤去できます。');
        }

        AssetAggregate::retrieve($command->assetId)
            ->removeFromInstallation(removedByUserId: $command->removedByUserId, removedAt: Carbon::now()->toIso8601String())
            ->persist();

        return Asset::query()->findOrFail($command->assetId);
    }
}
