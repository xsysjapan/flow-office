<?php

namespace App\Models;

final class PaidLeaveRequestStatus
{
    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const RETURNED = 'returned';

    public const CANCELLED = 'cancelled';
}
