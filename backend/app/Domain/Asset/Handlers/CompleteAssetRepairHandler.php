<?php

namespace App\Domain\Asset\Handlers;

use App\Domain\Asset\Aggregates\AssetAggregate;
use App\Domain\Asset\Commands\CompleteAssetRepair;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Asset;
use App\Models\AssetInstallationStatus;
use App\Models\AssetLendingStatus;
use App\Models\AssetManagementType;

/** @implements CommandHandler<CompleteAssetRepair> */
class CompleteAssetRepairHandler implements CommandHandler
{
    public function handle(Command $command): Asset
    {
        assert($command instanceof CompleteAssetRepair);

        $asset = Asset::query()->findOrFail($command->assetId);

        if ($asset->management_type === AssetManagementType::LENDING) {
            if ($asset->lending_status !== AssetLendingStatus::REPAIR) {
                throw new DomainRuleException('修理中の備品のみ修理完了にできます。');
            }
        } else {
            if ($asset->installation_status !== AssetInstallationStatus::REPAIR) {
                throw new DomainRuleException('修理中の備品のみ修理完了にできます。');
            }
        }

        AssetAggregate::retrieve($command->assetId)
            ->completeRepair(note: $command->note, completedByUserId: $command->completedByUserId)
            ->persist();

        return Asset::query()->findOrFail($command->assetId);
    }
}
