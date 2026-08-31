<?php

namespace App\Domain\Asset\Projectors;

use App\Domain\Asset\Events\AssetDefaultLocationSet;
use App\Domain\Asset\Events\AssetDeleted;
use App\Domain\Asset\Events\AssetDetailsUpdated;
use App\Domain\Asset\Events\AssetDisposed;
use App\Domain\Asset\Events\AssetInstalled;
use App\Domain\Asset\Events\AssetLendingMethodChanged;
use App\Domain\Asset\Events\AssetLoaned;
use App\Domain\Asset\Events\AssetManagementTypeChanged;
use App\Domain\Asset\Events\AssetQrCodeReissued;
use App\Domain\Asset\Events\AssetRecoveredFromLost;
use App\Domain\Asset\Events\AssetRegistered;
use App\Domain\Asset\Events\AssetRelocated;
use App\Domain\Asset\Events\AssetRemovedFromInstallation;
use App\Domain\Asset\Events\AssetRepairCompleted;
use App\Domain\Asset\Events\AssetRepairStarted;
use App\Domain\Asset\Events\AssetReportedLost;
use App\Domain\Asset\Events\AssetReturned;
use App\Models\Asset;
use App\Models\AssetInstallationStatus;
use App\Models\AssetLendingStatus;
use App\Models\AssetManagementType;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * asset.* イベントから assets / asset_default_location_changes / asset_placements /
 * asset_loans を作成・更新する(.claude/skills/add-projection参照)。
 */
class AssetProjector extends Projector
{
    public function onAssetRegistered(AssetRegistered $event): void
    {
        $isLending = $event->managementType === AssetManagementType::LENDING;

        Asset::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'asset_no' => $event->assetNo,
                'name' => $event->name,
                'category' => $event->category,
                'serial_number' => $event->serialNumber,
                'management_type' => $event->managementType,
                'lending_status' => $isLending ? AssetLendingStatus::AVAILABLE : null,
                'installation_status' => $isLending ? null : AssetInstallationStatus::STORED,
                'lending_method' => $isLending ? $event->lendingMethod : null,
                'default_location_text' => $isLending ? $event->defaultLocationText : null,
                'qr_token' => $event->qrToken,
                'current_loan_id' => null,
                'notes' => $event->notes,
            ],
        );
    }

    public function onAssetDetailsUpdated(AssetDetailsUpdated $event): void
    {
        Asset::query()->whereKey($event->aggregateRootUuid())->update([
            'name' => $event->name,
            'category' => $event->category,
            'serial_number' => $event->serialNumber,
            'notes' => $event->notes,
        ]);
    }

    public function onAssetDeleted(AssetDeleted $event): void
    {
        // asset_default_location_changes / asset_placements / asset_loans は
        // 外部キーのcascadeOnDeleteで併せて削除される。stored_eventsは削除しない
        // (spec 論点9)。
        Asset::query()->whereKey($event->aggregateRootUuid())->delete();
    }

    public function onAssetManagementTypeChanged(AssetManagementTypeChanged $event): void
    {
        $isLending = $event->managementType === AssetManagementType::LENDING;

        Asset::query()->whereKey($event->aggregateRootUuid())->update([
            'management_type' => $event->managementType,
            'lending_status' => $isLending ? AssetLendingStatus::AVAILABLE : null,
            'installation_status' => $isLending ? null : AssetInstallationStatus::STORED,
            'lending_method' => null,
            'default_location_text' => null,
            'current_loan_id' => null,
        ]);
    }

    public function onAssetLendingMethodChanged(AssetLendingMethodChanged $event): void
    {
        Asset::query()->whereKey($event->aggregateRootUuid())->update([
            'lending_method' => $event->lendingMethod,
        ]);
    }

    public function onAssetQrCodeReissued(AssetQrCodeReissued $event): void
    {
        Asset::query()->whereKey($event->aggregateRootUuid())->update([
            'qr_token' => $event->qrToken,
        ]);
    }

    public function onAssetDefaultLocationSet(AssetDefaultLocationSet $event): void
    {
        $asset = Asset::query()->find($event->aggregateRootUuid());
        if ($asset === null) {
            return;
        }

        $asset->default_location_text = $event->locationText;
        $asset->save();

        $asset->defaultLocationChanges()->create([
            'location_text' => $event->locationText,
            'changed_by_user_id' => $event->setByUserId,
            'changed_at' => $event->createdAt(),
        ]);
    }

    public function onAssetLoaned(AssetLoaned $event): void
    {
        $asset = Asset::query()->find($event->aggregateRootUuid());
        if ($asset === null) {
            return;
        }

        $asset->loans()->create([
            'id' => $event->loanId,
            'user_id' => $event->borrowerUserId,
            'loan_request_id' => $event->loanRequestId,
            'loaned_at' => $event->loanedAt,
            'expected_return_at' => $event->expectedReturnAt,
            'loaned_by_user_id' => $event->lentByUserId,
        ]);

        $asset->lending_status = AssetLendingStatus::LOANED;
        $asset->current_loan_id = $event->loanId;
        $asset->save();
    }

    public function onAssetReturned(AssetReturned $event): void
    {
        $asset = Asset::query()->find($event->aggregateRootUuid());
        if ($asset === null) {
            return;
        }

        $asset->loans()->whereKey($event->loanId)->update([
            'returned_at' => $event->returnedAt,
            'returned_by_user_id' => $event->returnedByUserId,
            'return_note' => $event->returnNote,
        ]);

        $asset->lending_status = AssetLendingStatus::AVAILABLE;
        $asset->current_loan_id = null;
        $asset->save();
    }

    public function onAssetInstalled(AssetInstalled $event): void
    {
        $asset = Asset::query()->find($event->aggregateRootUuid());
        if ($asset === null) {
            return;
        }

        $asset->placements()->create([
            'location_text' => $event->locationText,
            'started_at' => $event->installedAt,
            'started_by_user_id' => $event->installedByUserId,
        ]);

        $asset->installation_status = AssetInstallationStatus::INSTALLED;
        $asset->save();
    }

    public function onAssetRelocated(AssetRelocated $event): void
    {
        $asset = Asset::query()->find($event->aggregateRootUuid());
        if ($asset === null) {
            return;
        }

        $this->endCurrentPlacement($asset, $event->relocatedByUserId, $event->relocatedAt);

        $asset->placements()->create([
            'location_text' => $event->locationText,
            'started_at' => $event->relocatedAt,
            'started_by_user_id' => $event->relocatedByUserId,
        ]);

        $asset->save();
    }

    public function onAssetRemovedFromInstallation(AssetRemovedFromInstallation $event): void
    {
        $asset = Asset::query()->find($event->aggregateRootUuid());
        if ($asset === null) {
            return;
        }

        $this->endCurrentPlacement($asset, $event->removedByUserId, $event->removedAt);

        $asset->installation_status = AssetInstallationStatus::STORED;
        $asset->save();
    }

    public function onAssetRepairStarted(AssetRepairStarted $event): void
    {
        $asset = Asset::query()->find($event->aggregateRootUuid());
        if ($asset === null) {
            return;
        }

        if ($asset->management_type === AssetManagementType::LENDING) {
            $asset->lending_status = AssetLendingStatus::REPAIR;
        } else {
            $asset->installation_status = AssetInstallationStatus::REPAIR;
        }
        $asset->save();
    }

    public function onAssetRepairCompleted(AssetRepairCompleted $event): void
    {
        $asset = Asset::query()->find($event->aggregateRootUuid());
        if ($asset === null) {
            return;
        }

        if ($asset->management_type === AssetManagementType::LENDING) {
            $asset->lending_status = AssetLendingStatus::AVAILABLE;
        } else {
            $asset->installation_status = AssetInstallationStatus::STORED;
        }
        $asset->save();
    }

    public function onAssetReportedLost(AssetReportedLost $event): void
    {
        $asset = Asset::query()->find($event->aggregateRootUuid());
        if ($asset === null) {
            return;
        }

        if ($asset->management_type === AssetManagementType::LENDING) {
            $asset->lending_status = AssetLendingStatus::LOST;
        } else {
            $asset->installation_status = AssetInstallationStatus::LOST;
        }
        $asset->save();
    }

    public function onAssetRecoveredFromLost(AssetRecoveredFromLost $event): void
    {
        $asset = Asset::query()->find($event->aggregateRootUuid());
        if ($asset === null) {
            return;
        }

        if ($asset->management_type === AssetManagementType::LENDING) {
            $asset->lending_status = $event->wasLoanedBeforeLoss ? AssetLendingStatus::LOANED : AssetLendingStatus::AVAILABLE;
        } else {
            $asset->installation_status = AssetInstallationStatus::STORED;
        }
        $asset->save();
    }

    public function onAssetDisposed(AssetDisposed $event): void
    {
        $asset = Asset::query()->find($event->aggregateRootUuid());
        if ($asset === null) {
            return;
        }

        if ($asset->management_type === AssetManagementType::LENDING) {
            $asset->lending_status = AssetLendingStatus::DISPOSED;
        } else {
            $asset->installation_status = AssetInstallationStatus::DISPOSED;
        }
        $asset->save();
    }

    private function endCurrentPlacement(Asset $asset, string $endedByUserId, string $endedAt): void
    {
        $current = $asset->placements()->whereNull('ended_at')->latest('started_at')->first();
        if ($current !== null) {
            $current->ended_at = $endedAt;
            $current->ended_by_user_id = $endedByUserId;
            $current->save();
        }
    }
}
