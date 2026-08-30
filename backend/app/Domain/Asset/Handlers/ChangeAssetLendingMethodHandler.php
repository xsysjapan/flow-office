<?php

namespace App\Domain\Asset\Handlers;

use App\Domain\Asset\Aggregates\AssetAggregate;
use App\Domain\Asset\Commands\ChangeAssetLendingMethod;
use App\Domain\Asset\Guards\AssetActiveBusinessGuard;
use App\Domain\Asset\Guards\AssetLendingMethodGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Asset;
use App\Models\AssetManagementType;

/** @implements CommandHandler<ChangeAssetLendingMethod> */
class ChangeAssetLendingMethodHandler implements CommandHandler
{
    public function __construct(
        private readonly AssetLendingMethodGuard $lendingMethodGuard,
        private readonly AssetActiveBusinessGuard $activeBusinessGuard,
    ) {}

    public function handle(Command $command): Asset
    {
        assert($command instanceof ChangeAssetLendingMethod);

        $asset = Asset::query()->findOrFail($command->assetId);

        if ($asset->management_type !== AssetManagementType::LENDING) {
            throw new DomainRuleException('貸出品以外の貸出方式は変更できません。');
        }

        $this->activeBusinessGuard->assertLendingMethodChangeable($asset);
        $this->lendingMethodGuard->assertSelfServiceHasDefaultLocation($command->lendingMethod, $asset->default_location_text);

        AssetAggregate::retrieve($command->assetId)
            ->changeLendingMethod(lendingMethod: $command->lendingMethod, changedByUserId: $command->changedByUserId)
            ->persist();

        return Asset::query()->findOrFail($command->assetId);
    }
}
