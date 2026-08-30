<?php

namespace App\Domain\Asset\Guards;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AssetLendingMethod;

/**
 * 貸出方式(lending_method)に応じた制約を検証する(spec 論点6)。既存`AttendanceDayAggregate`と
 * 同じ方針で、Aggregate自体は不変条件を意識せず、CommandHandlerがProjectionを読んで
 * このGuardに検証を委ねる。
 */
class AssetLendingMethodGuard
{
    /**
     * self_serviceはdefault_location_textが設定済みであることを要求する
     * (RegisterAsset/ChangeAssetLendingMethod双方から呼ばれる)。
     */
    public function assertSelfServiceHasDefaultLocation(string $lendingMethod, ?string $defaultLocationText): void
    {
        if ($lendingMethod === AssetLendingMethod::SELF_SERVICE && ($defaultLocationText === null || trim($defaultLocationText) === '')) {
            throw new DomainRuleException('セルフサービス方式にするには通常配置場所の設定が必要です。');
        }
    }
}
