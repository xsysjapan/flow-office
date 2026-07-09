<?php

namespace App\Models;

/**
 * workflow_requests.status の許容値。
 */
final class WorkflowRequestStatus
{
    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const RETURNED = 'returned';

    public const CANCELLED = 'cancelled';

    /**
     * 取消可能なステータス (UC-W005)。
     *
     * @return array<int, string>
     */
    public static function cancellable(): array
    {
        return [self::DRAFT, self::SUBMITTED, self::RETURNED];
    }
}
