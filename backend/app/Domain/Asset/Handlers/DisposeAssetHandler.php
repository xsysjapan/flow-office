<?php

namespace App\Domain\Asset\Handlers;

use App\Domain\Asset\Aggregates\AssetAggregate;
use App\Domain\Asset\Commands\DisposeAsset;
use App\Domain\Asset\Guards\AssetActiveBusinessGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Asset;
use App\Models\AssetInstallationStatus;
use App\Models\AssetLendingStatus;
use App\Models\AssetManagementType;

/** @implements CommandHandler<DisposeAsset> */
class DisposeAssetHandler implements CommandHandler
{
    public function __construct(private readonly AssetActiveBusinessGuard $guard) {}

    public function handle(Command $command): Asset
    {
        assert($command instanceof DisposeAsset);

        $asset = Asset::query()->findOrFail($command->assetId);
        $this->guard->assertDisposable($asset);

        if ($asset->management_type === AssetManagementType::LENDING) {
            if ($asset->lending_status === AssetLendingStatus::DISPOSED) {
                throw new DomainRuleException('既に廃棄済みです。');
            }
        } else {
            if (in_array($asset->installation_status, [AssetInstallationStatus::INSTALLED, AssetInstallationStatus::REPAIR], true)) {
                throw new DomainRuleException('設置中または修理中の備品は廃棄できません。');
            }
            if ($asset->installation_status === AssetInstallationStatus::DISPOSED) {
                throw new DomainRuleException('既に廃棄済みです。');
            }
        }

        AssetAggregate::retrieve($command->assetId)
            ->dispose(note: $command->note, disposedByUserId: $command->disposedByUserId)
            ->persist();

        return Asset::query()->findOrFail($command->assetId);
    }
}
