<?php

namespace App\Domain\Asset\Guards;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Asset;
use App\Models\AssetInstallationStatus;
use App\Models\AssetLendingStatus;
use App\Models\AssetLoanRequest;
use App\Models\AssetLoanRequestStatus;

/**
 * 削除・廃棄・管理区分変更・貸出方式変更の実行可否を、貸出中/修理中/設置中/進行中の申請の
 * 有無から判定する(spec「削除可否ガード」「仕様確定事項」)。
 *
 * 承認待ち(pending)・承認済み未貸与(approved)の`asset_loan_requests`(貸出申請)は、
 * `workflow_requests`側のイベント(request_types.code=asset_loan)を購読する
 * `App\Domain\Asset\Reactors\AssetLoanRequestOnWorkflowRequestReactor`が更新する
 * 読み取り専用Projection。
 */
class AssetActiveBusinessGuard
{
    public function assertDeletable(Asset $asset): void
    {
        $this->assertNoActiveLendingOrInstallationBusiness($asset, '削除');
    }

    public function assertDisposable(Asset $asset): void
    {
        $this->assertNoLoanedOrRepair($asset, '廃棄');
    }

    public function assertManagementTypeChangeable(Asset $asset): void
    {
        $this->assertNoActiveLendingOrInstallationBusiness($asset, '管理区分変更');
    }

    public function assertLendingMethodChangeable(Asset $asset): void
    {
        $this->assertNoLoanedOrRepair($asset, '貸出方式変更');
    }

    private function assertNoLoanedOrRepair(Asset $asset, string $operationLabel): void
    {
        if (in_array($asset->lending_status, [AssetLendingStatus::LOANED, AssetLendingStatus::REPAIR], true)) {
            throw new DomainRuleException("貸出中または修理中の備品は{$operationLabel}できません。");
        }
    }

    private function assertNoActiveLendingOrInstallationBusiness(Asset $asset, string $operationLabel): void
    {
        $this->assertNoLoanedOrRepair($asset, $operationLabel);

        if (in_array($asset->installation_status, [AssetInstallationStatus::INSTALLED, AssetInstallationStatus::REPAIR], true)) {
            throw new DomainRuleException("設置中または修理中の備品は{$operationLabel}できません。");
        }

        $hasPendingLoanRequest = AssetLoanRequest::query()
            ->where('asset_id', $asset->id)
            ->whereIn('status', AssetLoanRequestStatus::active())
            ->exists();

        if ($hasPendingLoanRequest) {
            throw new DomainRuleException("承認待ちまたは承認済み未貸与の貸出申請がある備品は{$operationLabel}できません。");
        }
    }
}
