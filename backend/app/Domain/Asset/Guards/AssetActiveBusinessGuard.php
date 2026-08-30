<?php

namespace App\Domain\Asset\Guards;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Asset;
use App\Models\AssetInstallationStatus;
use App\Models\AssetLendingStatus;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;

/**
 * 削除・廃棄・管理区分変更・貸出方式変更の実行可否を、貸出中/修理中/設置中/進行中の申請の
 * 有無から判定する(spec「削除可否ガード」「仕様確定事項」)。
 *
 * 承認待ち(pending)・承認済み未貸与(approved)の`asset_loan_requests`(貸出申請)は
 * `workflow_requests`(subject_type=AssetLoanRequest, subject_id=assetId)を購読するReactorが
 * 更新するProjectionだが、本フェーズ(ドメイン基盤のみ)では`request_types`側の
 * `asset_loan`連携をまだ構築していないため、`workflow_requests`を直接
 * subject_type='AssetLoanRequest'で検索する形で将来の連携をそのまま拾える実装にしておく
 * (連携が未構築の間は該当行が存在せず、実質的にno-opとなる)。
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

        $hasPendingLoanRequest = WorkflowRequest::query()
            ->where('subject_type', 'AssetLoanRequest')
            ->where('subject_id', $asset->id)
            ->whereIn('status', [WorkflowRequestStatus::SUBMITTED, WorkflowRequestStatus::APPROVED])
            ->exists();

        if ($hasPendingLoanRequest) {
            throw new DomainRuleException("承認待ちまたは承認済み未貸与の貸出申請がある備品は{$operationLabel}できません。");
        }
    }
}
