<?php

namespace App\Domain\Asset\Aggregates;

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
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * 備品(貸出品・設置品)集約。1つのAggregateで貸出・返却・設置・移設・撤去・修理・紛失・
 * 廃棄・削除など、備品本体に対するすべての業務操作を記録する(spec 論点1・論点3)。
 *
 * 業務ルール(貸出方式ごとの制約・状態遷移の可否等)はAggregate自身では判定せず、
 * `Handlers/`配下のCommandHandlerが`assets`Projectionを読んで検証する
 * (既存`AttendanceDayAggregate`と同じ方針。spec 論点6)。そのためAggregateは
 * `recordThat`するだけの薄いレイヤーであり、`apply*`によるリプレイ用の内部状態を持たない。
 */
class AssetAggregate extends AggregateRoot
{
    public function register(
        string $assetNo,
        string $name,
        string $category,
        ?string $serialNumber,
        string $managementType,
        ?string $lendingMethod,
        ?string $defaultLocationText,
        string $qrToken,
        ?string $notes,
        string $registeredByUserId,
    ): self {
        $this->recordThat(new AssetRegistered(
            assetNo: $assetNo,
            name: $name,
            category: $category,
            serialNumber: $serialNumber,
            managementType: $managementType,
            lendingMethod: $lendingMethod,
            defaultLocationText: $defaultLocationText,
            qrToken: $qrToken,
            notes: $notes,
            registeredByUserId: $registeredByUserId,
        ));

        return $this;
    }

    public function updateDetails(
        string $name,
        string $category,
        ?string $serialNumber,
        ?string $notes,
        string $updatedByUserId,
    ): self {
        $this->recordThat(new AssetDetailsUpdated(
            name: $name,
            category: $category,
            serialNumber: $serialNumber,
            notes: $notes,
            updatedByUserId: $updatedByUserId,
        ));

        return $this;
    }

    public function delete(string $deletedByUserId): self
    {
        $this->recordThat(new AssetDeleted(deletedByUserId: $deletedByUserId));

        return $this;
    }

    public function changeManagementType(string $managementType, string $changedByUserId): self
    {
        $this->recordThat(new AssetManagementTypeChanged(
            managementType: $managementType,
            changedByUserId: $changedByUserId,
        ));

        return $this;
    }

    public function changeLendingMethod(string $lendingMethod, string $changedByUserId): self
    {
        $this->recordThat(new AssetLendingMethodChanged(
            lendingMethod: $lendingMethod,
            changedByUserId: $changedByUserId,
        ));

        return $this;
    }

    public function reissueQrCode(string $qrToken, string $reissuedByUserId): self
    {
        $this->recordThat(new AssetQrCodeReissued(qrToken: $qrToken, reissuedByUserId: $reissuedByUserId));

        return $this;
    }

    public function setDefaultLocation(string $locationText, string $setByUserId): self
    {
        $this->recordThat(new AssetDefaultLocationSet(locationText: $locationText, setByUserId: $setByUserId));

        return $this;
    }

    public function lend(
        string $loanId,
        string $borrowerUserId,
        string $lentByUserId,
        ?string $expectedReturnAt,
        ?string $loanRequestId,
        string $loanedAt,
    ): self {
        $this->recordThat(new AssetLoaned(
            loanId: $loanId,
            borrowerUserId: $borrowerUserId,
            lentByUserId: $lentByUserId,
            expectedReturnAt: $expectedReturnAt,
            loanRequestId: $loanRequestId,
            loanedAt: $loanedAt,
        ));

        return $this;
    }

    public function returnAsset(
        string $loanId,
        string $returnedByUserId,
        ?string $returnNote,
        string $returnedAt,
    ): self {
        $this->recordThat(new AssetReturned(
            loanId: $loanId,
            returnedByUserId: $returnedByUserId,
            returnNote: $returnNote,
            returnedAt: $returnedAt,
        ));

        return $this;
    }

    public function install(string $locationText, string $installedByUserId, string $installedAt): self
    {
        $this->recordThat(new AssetInstalled(
            locationText: $locationText,
            installedByUserId: $installedByUserId,
            installedAt: $installedAt,
        ));

        return $this;
    }

    public function relocate(string $locationText, string $relocatedByUserId, string $relocatedAt): self
    {
        $this->recordThat(new AssetRelocated(
            locationText: $locationText,
            relocatedByUserId: $relocatedByUserId,
            relocatedAt: $relocatedAt,
        ));

        return $this;
    }

    public function removeFromInstallation(string $removedByUserId, string $removedAt): self
    {
        $this->recordThat(new AssetRemovedFromInstallation(
            removedByUserId: $removedByUserId,
            removedAt: $removedAt,
        ));

        return $this;
    }

    public function startRepair(?string $note, string $startedByUserId): self
    {
        $this->recordThat(new AssetRepairStarted(note: $note, startedByUserId: $startedByUserId));

        return $this;
    }

    public function completeRepair(?string $note, string $completedByUserId): self
    {
        $this->recordThat(new AssetRepairCompleted(note: $note, completedByUserId: $completedByUserId));

        return $this;
    }

    public function reportLost(?string $note, string $reportedByUserId): self
    {
        $this->recordThat(new AssetReportedLost(note: $note, reportedByUserId: $reportedByUserId));

        return $this;
    }

    public function recoverFromLost(bool $wasLoanedBeforeLoss, string $recoveredByUserId): self
    {
        $this->recordThat(new AssetRecoveredFromLost(
            wasLoanedBeforeLoss: $wasLoanedBeforeLoss,
            recoveredByUserId: $recoveredByUserId,
        ));

        return $this;
    }

    public function dispose(?string $note, string $disposedByUserId): self
    {
        $this->recordThat(new AssetDisposed(note: $note, disposedByUserId: $disposedByUserId));

        return $this;
    }
}
