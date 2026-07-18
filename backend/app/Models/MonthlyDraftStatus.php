<?php

namespace App\Models;

/**
 * monthly_attendance_drafts.status。docs/26-usecases-monthly-import.md参照。
 */
final class MonthlyDraftStatus
{
    public const DRAFT = 'draft';

    public const VALIDATING = 'validating';

    public const NEEDS_REVIEW = 'needs_review';

    public const READY_TO_SUBMIT = 'ready_to_submit';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const LOCKED = 'locked';
}
