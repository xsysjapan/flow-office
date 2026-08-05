<?php

namespace App\Models;

final class CompensatoryLeaveRequestStatus
{
    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const RETURNED = 'returned';

    public const CANCELLED = 'cancelled';
}
