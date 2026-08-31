<?php

namespace App\Domain\Asset\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * asset.reported_lost
 *
 * 貸出中の備品を紛失した場合も借用者情報(current_loan_id/asset_loans)は保持したまま
 * lending_statusのみlostへ遷移する(spec「状態遷移」)。
 */
class AssetReportedLost extends ShouldBeStored
{
    public function __construct(
        public readonly ?string $note,
        public readonly string $reportedByUserId,
    ) {}
}
