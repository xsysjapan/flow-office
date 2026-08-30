<?php

namespace App\Domain\Asset\Handlers;

use App\Domain\Asset\Aggregates\AssetAggregate;
use App\Domain\Asset\Commands\ReportAssetLost;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Asset;
use App\Models\AssetInstallationStatus;
use App\Models\AssetLendingStatus;
use App\Models\AssetManagementType;

/** @implements CommandHandler<ReportAssetLost> */
class ReportAssetLostHandler implements CommandHandler
{
    public function handle(Command $command): Asset
    {
        assert($command instanceof ReportAssetLost);

        $asset = Asset::query()->findOrFail($command->assetId);

        if ($asset->management_type === AssetManagementType::LENDING) {
            if (! in_array($asset->lending_status, [AssetLendingStatus::AVAILABLE, AssetLendingStatus::LOANED], true)) {
                throw new DomainRuleException('貸出可能または貸出中の備品のみ紛失報告できます。');
            }
        } else {
            if (! in_array($asset->installation_status, [AssetInstallationStatus::STORED, AssetInstallationStatus::INSTALLED], true)) {
                throw new DomainRuleException('保管中または設置中の備品のみ紛失報告できます。');
            }
        }

        AssetAggregate::retrieve($command->assetId)
            ->reportLost(note: $command->note, reportedByUserId: $command->reportedByUserId)
            ->persist();

        return Asset::query()->findOrFail($command->assetId);
    }
}
