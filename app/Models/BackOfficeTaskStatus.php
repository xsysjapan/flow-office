<?php

namespace App\Models;

/**
 * backoffice_tasks.status の許容値 (docs/11-usecases-backoffice.md UC-B003)。
 * 承認とは別ステータス系列であることに注意。
 */
final class BackOfficeTaskStatus
{
    public const NOT_STARTED = 'not_started';

    public const IN_REVIEW = 'in_review';

    public const NEEDS_FIX = 'needs_fix';

    public const PROCESSING = 'processing';

    public const ORDERED = 'ordered';

    public const PAYMENT_SCHEDULED = 'payment_scheduled';

    public const SHIPPED = 'shipped';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::NOT_STARTED,
            self::IN_REVIEW,
            self::NEEDS_FIX,
            self::PROCESSING,
            self::ORDERED,
            self::PAYMENT_SCHEDULED,
            self::SHIPPED,
            self::COMPLETED,
            self::CANCELLED,
        ];
    }
}
