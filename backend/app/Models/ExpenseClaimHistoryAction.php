<?php

namespace App\Models;

/**
 * expense_claim_history_entries.action の許容値。
 */
final class ExpenseClaimHistoryAction
{
    public const DRAFTED = 'drafted';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const RETURNED = 'returned';

    public const CANCELLED = 'cancelled';
}
