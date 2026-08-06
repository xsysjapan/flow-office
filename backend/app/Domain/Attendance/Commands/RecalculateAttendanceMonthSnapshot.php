<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 提出済み・承認済み・締め済みの月次勤怠1件について、集計ロジックの追加・修正を反映するため
 * snapshot_jsonを再計算する(過去データ補正。attendance:recalculate-month-snapshotsコマンド参照)。
 */
class RecalculateAttendanceMonthSnapshot implements Command
{
    public function __construct(
        public readonly string $attendanceMonthId,
    ) {}
}
