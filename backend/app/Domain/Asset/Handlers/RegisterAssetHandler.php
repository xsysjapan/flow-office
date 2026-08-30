<?php

namespace App\Domain\Asset\Handlers;

use App\Domain\Asset\Aggregates\AssetAggregate;
use App\Domain\Asset\Commands\RegisterAsset;
use App\Domain\Asset\Guards\AssetLendingMethodGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Asset;
use App\Models\AssetManagementType;
use Illuminate\Support\Str;

/** @implements CommandHandler<RegisterAsset> */
class RegisterAssetHandler implements CommandHandler
{
    public function __construct(private readonly AssetLendingMethodGuard $lendingMethodGuard) {}

    public function handle(Command $command): Asset
    {
        assert($command instanceof RegisterAsset);

        if (! in_array($command->managementType, [AssetManagementType::LENDING, AssetManagementType::INSTALLATION], true)) {
            throw new DomainRuleException('管理区分はlendingまたはinstallationを指定してください。');
        }

        if ($command->managementType === AssetManagementType::LENDING && $command->lendingMethod !== null) {
            $this->lendingMethodGuard->assertSelfServiceHasDefaultLocation($command->lendingMethod, $command->defaultLocationText);
        }

        $assetId = (string) Str::uuid();
        $qrToken = (string) Str::random(32);

        AssetAggregate::retrieve($assetId)
            ->register(
                assetNo: $command->assetNo,
                name: $command->name,
                category: $command->category,
                serialNumber: $command->serialNumber,
                managementType: $command->managementType,
                lendingMethod: $command->managementType === AssetManagementType::LENDING ? $command->lendingMethod : null,
                defaultLocationText: $command->managementType === AssetManagementType::LENDING ? $command->defaultLocationText : null,
                qrToken: $qrToken,
                notes: $command->notes,
                registeredByUserId: $command->registeredByUserId,
            )
            ->persist();

        return Asset::query()->findOrFail($assetId);
    }
}
