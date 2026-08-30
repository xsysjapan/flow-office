<?php

namespace App\Domain\Asset\Handlers;

use App\Domain\Asset\Aggregates\AssetAggregate;
use App\Domain\Asset\Commands\StartAssetRepair;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Asset;
use App\Models\AssetInstallationStatus;
use App\Models\AssetLendingStatus;
use App\Models\AssetManagementType;

/** @implements CommandHandler<StartAssetRepair> */
class StartAssetRepairHandler implements CommandHandler
{
    public function handle(Command $command): Asset
    {
        assert($command instanceof StartAssetRepair);

        $asset = Asset::query()->findOrFail($command->assetId);

        if ($asset->management_type === AssetManagementType::LENDING) {
            if ($asset->lending_status !== AssetLendingStatus::AVAILABLE) {
                throw new DomainRuleException('貸出可能な状態の備品のみ修理を開始できます。');
            }
        } else {
            if (! in_array($asset->installation_status, [AssetInstallationStatus::STORED, AssetInstallationStatus::INSTALLED], true)) {
                throw new DomainRuleException('保管中または設置中の備品のみ修理を開始できます。');
            }
        }

        AssetAggregate::retrieve($command->assetId)
            ->startRepair(note: $command->note, startedByUserId: $command->startedByUserId)
            ->persist();

        return Asset::query()->findOrFail($command->assetId);
    }
}
