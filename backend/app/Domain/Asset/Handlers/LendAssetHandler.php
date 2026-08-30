<?php

namespace App\Domain\Asset\Handlers;

use App\Domain\Asset\Aggregates\AssetAggregate;
use App\Domain\Asset\Commands\LendAsset;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Asset;
use App\Models\AssetLendingMethod;
use App\Models\AssetLendingStatus;
use App\Models\AssetLoanRequest;
use App\Models\AssetLoanRequestStatus;
use App\Models\AssetManagementType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * self_service/backoffice/approvalすべてこの1つのHandlerを通る。「誰が呼べるか」
 * (本人限定/asset.manage権限保有者限定)はController側の前提条件チェックで行う想定
 * だが(spec 論点1)、「approval方式は承認済み・未貸与の申請が必要」という制約は
 * Aggregateの不変条件ではなくCommandHandlerがProjectionを読んで検証する
 * (spec 論点6と同じ方針)ため、ここで検証する。
 *
 * @implements CommandHandler<LendAsset>
 */
class LendAssetHandler implements CommandHandler
{
    public function handle(Command $command): Asset
    {
        assert($command instanceof LendAsset);

        $asset = Asset::query()->findOrFail($command->assetId);

        if ($asset->management_type !== AssetManagementType::LENDING) {
            throw new DomainRuleException('貸出品以外は貸与できません。');
        }

        if ($asset->lending_status !== AssetLendingStatus::AVAILABLE) {
            throw new DomainRuleException('貸出可能な状態の備品のみ貸与できます。');
        }

        if ($asset->lending_method === AssetLendingMethod::APPROVAL) {
            $this->assertApprovedLoanRequest($asset, $command);
        }

        $loanId = (string) Str::uuid();

        AssetAggregate::retrieve($command->assetId)
            ->lend(
                loanId: $loanId,
                borrowerUserId: $command->borrowerUserId,
                lentByUserId: $command->lentByUserId,
                expectedReturnAt: $command->expectedReturnAt,
                loanRequestId: $command->loanRequestId,
                loanedAt: Carbon::now()->toIso8601String(),
            )
            ->persist();

        return Asset::query()->findOrFail($command->assetId);
    }

    /**
     * approval方式では`loanRequestId`の指定と、それが対象asset・借用者に紐づく承認済み・
     * 未貸与の`asset_loan_requests`であることを要求する(spec「貸出方式(lending_method)と
     * LendAsset呼び出し条件」)。同一資産に対する複数の承認済み申請がある場合にどれを使うかは
     * システムが自動選択せず、呼び出し側(バックオフィス)が明示的に選んだ`loanRequestId`を
     * そのまま信用する(spec 論点2-3)。
     */
    private function assertApprovedLoanRequest(Asset $asset, LendAsset $command): void
    {
        if ($command->loanRequestId === null) {
            throw new DomainRuleException('承認制の備品を貸与するには、承認済みの貸出申請を指定してください。');
        }

        $loanRequest = AssetLoanRequest::query()->find($command->loanRequestId);

        if ($loanRequest === null
            || $loanRequest->status !== AssetLoanRequestStatus::APPROVED
            || $loanRequest->asset_id !== $asset->id
            || $loanRequest->applicant_user_id !== $command->borrowerUserId
        ) {
            throw new DomainRuleException('指定された貸出申請は、承認済み・未貸与かつ対象の備品・借用者と一致している必要があります。');
        }
    }
}
