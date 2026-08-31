<?php

namespace App\Domain\Asset\Handlers;

use App\Domain\Asset\Aggregates\AssetAggregate;
use App\Domain\Asset\Commands\RelocateAsset;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Asset;
use App\Models\AssetInstallationStatus;
use Illuminate\Support\Carbon;

/** @implements CommandHandler<RelocateAsset> */
class RelocateAssetHandler implements CommandHandler
{
    public function handle(Command $command): Asset
    {
        assert($command instanceof RelocateAsset);

        $asset = Asset::query()->findOrFail($command->assetId);

        if ($asset->installation_status !== AssetInstallationStatus::INSTALLED) {
            throw new DomainRuleException('設置中の備品のみ移設できます。');
        }

        AssetAggregate::retrieve($command->assetId)
            ->relocate(locationText: $command->locationText, relocatedByUserId: $command->relocatedByUserId, relocatedAt: Carbon::now()->toIso8601String())
            ->persist();

        return Asset::query()->findOrFail($command->assetId);
    }
}
