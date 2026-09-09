<?php

namespace App\Domain\PaidLeaveSchedule\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * `RecalculateFutureSchedule`により、非確定(Granted/Cancelled以外)のエントリが
 * 新しい算出結果で置き換えられた(または`manualOverride`済みのため置き換えられず
 * NeedsReviewへ押し出された)ことを記録する。監査目的で新旧の内容を両方保持する
 * (spec.md論点7・ドメインモデル参照)。
 */
class PaidLeaveScheduleEntrySuperseded extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $entryId,
        public readonly string $reason,
        public readonly string $previousCategory,
        public readonly float $previousCandidateGrantDays,
        public readonly bool $wasManuallyOverridden,
        public readonly ?string $newCategory,
        public readonly ?float $newCandidateGrantDays,
        public readonly bool $pushedToNeedsReview,
    ) {}
}
