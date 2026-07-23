<?php

namespace App\Models;

/**
 * workflow_request_history_entries.action の許容値。
 */
final class WorkflowRequestHistoryAction
{
    public const DRAFTED = 'drafted';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const RETURNED = 'returned';

    public const CANCELLED = 'cancelled';
}
