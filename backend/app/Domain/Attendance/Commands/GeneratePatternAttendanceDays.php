<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 週次パターン(曜日ごとの実際の出退勤時刻・休憩時刻)を指定期間へ一括展開し、
 * 必要に応じて日単位の上書きを適用して実績(attendance_days)を作成・更新する。
 * 週次一括入力は`dayOverrides`を空で呼び出し、月次一括入力はそれに加えて
 * 特定日の上書きを渡す。日次ロジックは複製せず、日ごとに既存の
 * `CreateAttendanceDay`/`EditAttendanceDay`をCommandBus経由で呼び出す
 * (GeneratePatternAttendanceDaysHandler参照)。
 */
class GeneratePatternAttendanceDays implements Command
{
    public const OVERWRITE_MODE_SKIP_EXISTING = 'skip_existing';

    public const OVERWRITE_MODE_OVERWRITE_EXISTING = 'overwrite_existing';

    /**
     * @param  array<int|string, array{start_time: string, end_time: string, break_start_time?: string, break_end_time?: string}|null>  $weeklyPattern
     * @param  array<string, array{start_time: string, end_time: string, break_start_time?: string, break_end_time?: string}|null>  $dayOverrides
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $from,
        public readonly string $to,
        public readonly array $weeklyPattern,
        public readonly array $dayOverrides,
        public readonly string $utcOffset,
        public readonly string $overwriteMode,
        public readonly string $reason,
        public readonly string $actingUserId,
    ) {}
}
