<?php

namespace App\Models;

/**
 * attendance_import_sessions.status。docs/26-usecases-monthly-import.md参照。
 */
final class ImportSessionStatus
{
    public const CREATED = 'created';

    public const PREVIEWING = 'previewing';

    public const PREVIEWED = 'previewed';

    public const APPLIED = 'applied';

    public const CANCELLED = 'cancelled';
}
