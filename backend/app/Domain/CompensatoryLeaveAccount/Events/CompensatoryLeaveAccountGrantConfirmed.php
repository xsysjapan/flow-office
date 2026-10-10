<?php

namespace App\Domain\CompensatoryLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 月次提出で下書きの付与を確定する。`expiresOn`がnullの付与は失効しない(無期限)。
 */
class CompensatoryLeaveAccountGrantConfirmed extends ShouldBeStored
{
    public function __construct(
        public readonly string $grantId,
        public readonly string $confirmedAt,
        public readonly ?string $expiresOn,
    ) {}
}
