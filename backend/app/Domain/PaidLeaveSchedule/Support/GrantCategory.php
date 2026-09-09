<?php

namespace App\Domain\PaidLeaveSchedule\Support;

/**
 * 通常/比例/シフト区分(依頼書§34-35)。判定に必要な列が未入力の場合はNEEDS_REVIEW。
 */
final class GrantCategory
{
    public const REGULAR = '通常';

    public const PROPORTIONAL = '比例';

    public const SHIFT = 'シフト';

    public const NEEDS_REVIEW = 'NeedsReview';
}
