<?php

namespace App\Domain\Asset\Handlers;

use App\Domain\Asset\Aggregates\AssetAggregate;
use App\Domain\Asset\Commands\RecoverAssetFromLost;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Asset;
use App\Models\AssetInstallationStatus;
use App\Models\AssetLendingStatus;
use App\Models\AssetManagementType;

/**
 * 発見時点で貸出中扱いだったか(current_loan_idが設定されたままか)を
 * `wasLoanedBeforeLoss`としてイベントに残す(spec「状態遷移」)。
 *
 * @implements CommandHandler<RecoverAssetFromLost>
 */
class RecoverAssetFromLostHandler implements CommandHandler
{
    public function handle(Command $command): Asset
    {
        assert($command instanceof RecoverAssetFromLost);

        $asset = Asset::query()->findOrFail($command->assetId);

        if ($asset->management_type === AssetManagementType::LENDING) {
            if ($asset->lending_status !== AssetLendingStatus::LOST) {
                throw new DomainRuleException('紛失中の備品のみ発見報告できます。');
            }
            $wasLoanedBeforeLoss = $asset->current_loan_id !== null;
        } else {
            if ($asset->installation_status !== AssetInstallationStatus::LOST) {
                throw new DomainRuleException('紛失中の備品のみ発見報告できます。');
            }
            $wasLoanedBeforeLoss = false;
        }

        AssetAggregate::retrieve($command->assetId)
            ->recoverFromLost(wasLoanedBeforeLoss: $wasLoanedBeforeLoss, recoveredByUserId: $command->recoveredByUserId)
            ->persist();

        return Asset::query()->findOrFail($command->assetId);
    }
}
