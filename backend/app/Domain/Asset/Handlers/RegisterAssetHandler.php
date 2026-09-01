<?php

namespace App\Domain\Asset\Handlers;

use App\Domain\Asset\Aggregates\AssetAggregate;
use App\Domain\Asset\Commands\RegisterAsset;
use App\Domain\Asset\Guards\AssetLendingMethodGuard;
use App\Domain\AssetNumbering\Commands\IssueAssetNumber;
use App\Domain\AssetNumbering\Handlers\IssueAssetNumberHandler;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Asset;
use App\Models\AssetManagementType;
use Illuminate\Support\Str;

/** @implements CommandHandler<RegisterAsset> */
class RegisterAssetHandler implements CommandHandler
{
    public function __construct(
        private readonly AssetLendingMethodGuard $lendingMethodGuard,
        private readonly IssueAssetNumberHandler $issueAssetNumberHandler,
    ) {}

    public function handle(Command $command): Asset
    {
        assert($command instanceof RegisterAsset);

        if (! in_array($command->managementType, [AssetManagementType::LENDING, AssetManagementType::INSTALLATION], true)) {
            throw new DomainRuleException('管理区分はlendingまたはinstallationを指定してください。');
        }

        if ($command->managementType === AssetManagementType::LENDING && $command->lendingMethod !== null) {
            $this->lendingMethodGuard->assertSelfServiceHasDefaultLocation($command->lendingMethod, $command->defaultLocationText);
        }

        // 論点10: `assetNo`が未指定(自動採番対象カテゴリ)の場合のみ採番する。
        // 直接API叩き等でカテゴリに有効なルールが無いのに`assetNo`を省略された場合は、
        // 手入力を促すバリデーションエラーとする(通常はフロント側が事前に判定している)。
        $assetNo = $command->assetNo;
        if ($assetNo === null) {
            $assetNo = $this->issueAssetNumberHandler->handle(new IssueAssetNumber(
                category: $command->category,
                actorUserId: $command->registeredByUserId,
            ));

            if ($assetNo === null) {
                throw new DomainRuleException('管理番号を入力してください。');
            }
        }

        $assetId = (string) Str::uuid();
        $qrToken = (string) Str::random(32);

        AssetAggregate::retrieve($assetId)
            ->register(
                assetNo: $assetNo,
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
