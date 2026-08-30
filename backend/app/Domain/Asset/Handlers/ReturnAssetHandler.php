<?php

namespace App\Domain\Asset\Handlers;

use App\Domain\Asset\Aggregates\AssetAggregate;
use App\Domain\Asset\Commands\ReturnAsset;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Asset;
use App\Models\AssetLoan;
use Illuminate\Support\Carbon;

/** @implements CommandHandler<ReturnAsset> */
class ReturnAssetHandler implements CommandHandler
{
    public function handle(Command $command): Asset
    {
        assert($command instanceof ReturnAsset);

        $asset = Asset::query()->findOrFail($command->assetId);

        $loan = AssetLoan::query()
            ->where('id', $command->loanId)
            ->where('asset_id', $command->assetId)
            ->whereNull('returned_at')
            ->first();

        if ($loan === null || $asset->current_loan_id !== $command->loanId) {
            throw new DomainRuleException('現在アクティブな貸出のみ返却できます。');
        }

        AssetAggregate::retrieve($command->assetId)
            ->returnAsset(
                loanId: $command->loanId,
                returnedByUserId: $command->returnedByUserId,
                returnNote: $command->returnNote,
                returnedAt: Carbon::now()->toIso8601String(),
            )
            ->persist();

        return Asset::query()->findOrFail($command->assetId);
    }
}
