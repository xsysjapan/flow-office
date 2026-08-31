<?php

namespace App\Domain\Asset\Handlers;

use App\Domain\Asset\Aggregates\AssetAggregate;
use App\Domain\Asset\Commands\ChangeAssetManagementType;
use App\Domain\Asset\Guards\AssetActiveBusinessGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Asset;
use App\Models\AssetManagementType;

/** @implements CommandHandler<ChangeAssetManagementType> */
class ChangeAssetManagementTypeHandler implements CommandHandler
{
    public function __construct(private readonly AssetActiveBusinessGuard $guard) {}

    public function handle(Command $command): Asset
    {
        assert($command instanceof ChangeAssetManagementType);

        if (! in_array($command->managementType, [AssetManagementType::LENDING, AssetManagementType::INSTALLATION], true)) {
            throw new DomainRuleException('管理区分はlendingまたはinstallationを指定してください。');
        }

        $asset = Asset::query()->findOrFail($command->assetId);

        if ($asset->management_type === $command->managementType) {
            throw new DomainRuleException('既にその管理区分です。');
        }

        $this->guard->assertManagementTypeChangeable($asset);

        AssetAggregate::retrieve($command->assetId)
            ->changeManagementType(managementType: $command->managementType, changedByUserId: $command->changedByUserId)
            ->persist();

        return Asset::query()->findOrFail($command->assetId);
    }
}
