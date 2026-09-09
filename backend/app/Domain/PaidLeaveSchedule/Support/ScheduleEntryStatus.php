<?php

namespace App\Domain\PaidLeaveSchedule\Support;

/**
 * PaidLeaveScheduleAggregateの状態遷移: Scheduled→AssessmentPending→
 * (Eligible|NotEligible|NeedsReview)→(Granted|Cancelled)。
 * 依頼書§30の命名をそのまま採用(spec.md「ドメインモデル」参照)。
 */
final class ScheduleEntryStatus
{
    public const SCHEDULED = 'Scheduled';

    public const ASSESSMENT_PENDING = 'AssessmentPending';

    public const ELIGIBLE = 'Eligible';

    public const NOT_ELIGIBLE = 'NotEligible';

    public const NEEDS_REVIEW = 'NeedsReview';

    public const GRANTED = 'Granted';

    public const CANCELLED = 'Cancelled';

    /** @var array<int, string> */
    public const TERMINAL = [self::GRANTED, self::CANCELLED];

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }
}
