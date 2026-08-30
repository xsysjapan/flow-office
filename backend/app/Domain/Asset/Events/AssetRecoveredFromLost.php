<?php

namespace App\Domain\Asset\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * asset.recovered_from_lost
 *
 * `wasLoanedBeforeLoss`は発見時点で貸出中扱いだったかを表す(spec「状態遷移」)。
 * trueならlending_statusはloanedへ、falseならavailableへ戻る。
 */
class AssetRecoveredFromLost extends ShouldBeStored
{
    public function __construct(
        public readonly bool $wasLoanedBeforeLoss,
        public readonly string $recoveredByUserId,
    ) {}
}
