<?php

namespace App\Models;

/**
 * attendance_import_items.status。docs/26-usecases-monthly-import.md参照。
 */
final class ImportItemStatus
{
    public const PENDING_REVIEW = 'pending_review';

    public const CONFIRMED = 'confirmed';

    public const EXCLUDED = 'excluded';
}
