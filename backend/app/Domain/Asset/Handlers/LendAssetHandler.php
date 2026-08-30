<?php

namespace App\Domain\Asset\Handlers;

use App\Domain\Asset\Aggregates\AssetAggregate;
use App\Domain\Asset\Commands\LendAsset;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Asset;
use App\Models\AssetLendingStatus;
use App\Models\AssetManagementType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * self_service/backoffice/approvalすべてこの1つのHandlerを通る。方式ごとの呼び出し可否
 * (誰が呼べるか・approval方式の承認済み申請の存在確認)はController側の前提条件チェックで
 * 行う想定であり(spec 論点1)、本フェーズ(ドメイン基盤)ではlending_status=availableで
 * あることのみを検証する。
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
}
