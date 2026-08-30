<?php

namespace App\Domain\Asset\Handlers;

use App\Domain\Asset\Aggregates\AssetAggregate;
use App\Domain\Asset\Commands\ReissueAssetQrCode;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\Asset;
use Illuminate\Support\Str;

/** @implements CommandHandler<ReissueAssetQrCode> */
class ReissueAssetQrCodeHandler implements CommandHandler
{
    public function handle(Command $command): Asset
    {
        assert($command instanceof ReissueAssetQrCode);

        Asset::query()->findOrFail($command->assetId);

        $qrToken = (string) Str::random(32);

        AssetAggregate::retrieve($command->assetId)
            ->reissueQrCode(qrToken: $qrToken, reissuedByUserId: $command->reissuedByUserId)
            ->persist();

        return Asset::query()->findOrFail($command->assetId);
    }
}
