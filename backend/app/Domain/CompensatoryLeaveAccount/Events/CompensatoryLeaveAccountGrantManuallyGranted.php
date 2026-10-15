<?php

namespace App\Domain\CompensatoryLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 管理者が休日出勤の対象日を指定して代休を手動付与する。作成と同時に確定(confirmed)する。
 */
class CompensatoryLeaveAccountGrantManuallyGranted extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $grantId,
        public readonly string $sourceWorkDate,
        public readonly float $grantedDays,
        public readonly ?int $grantedMinutes,
        public readonly ?string $expiresOn,
        public readonly ?string $grantReason,
    ) {}
}
