<?php

namespace App\Models;

/**
 * attendance_punches.status。訂正・削除された打刻ログも行を残したまま参照できるようにする
 * (docs/07-usecases-attendance.md UC-A013/UC-A014)。
 */
final class PunchStatus
{
    public const ACTIVE = 'active';

    public const CORRECTED = 'corrected';

    public const DELETED = 'deleted';
}
