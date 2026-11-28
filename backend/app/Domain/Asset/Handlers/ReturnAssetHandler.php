<?php

namespace App\Domain\Asset\Handlers;

use App\Domain\AccessControl\Services\EffectiveAccessResolver;
use App\Domain\Asset\Aggregates\AssetAggregate;
use App\Domain\Asset\Commands\ReturnAsset;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Asset;
use App\Models\AssetLoan;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;

/**
 * 借用者本人以外が返却する(他人の貸出の返却)には、全社の`asset.manage`を要する(単件・一括とも)。
 *
 * @implements CommandHandler<ReturnAsset>
 */
class ReturnAssetHandler implements CommandHandler
{
    public function __construct(private readonly EffectiveAccessResolver $access) {}

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

        if ((string) $loan->user_id !== $command->returnedByUserId) {
            $returnedBy = User::query()->find($command->returnedByUserId);

            if ($returnedBy === null || ! $this->access->hasGlobalPermission($returnedBy, 'asset.manage')) {
                throw new AuthorizationException('他人の貸出の返却には、備品の管理権限が必要です。');
            }
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
