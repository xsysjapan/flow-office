<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 週次パターン(曜日ごとの開始/終了時刻・休憩分)を指定期間へ一括展開し、
 * 必要に応じて日単位の上書きを適用して勤務予定を生成する。
 * 週次一括入力は`dayOverrides`を空で呼び出し、月次一括入力はそれに加えて
 * 特定日の上書きを渡す。
 */
class GeneratePatternShiftAssignments implements Command
{
    public const OVERWRITE_MODE_SKIP_EDITED = 'skip_edited';

    public const OVERWRITE_MODE_OVERWRITE_ALL = 'overwrite_all';

    /**
     * @param  array<int|string, array{start_time: string, end_time: string, break_minutes: int}|null>  $weeklyPattern
     * @param  array<string, array{start_time: string, end_time: string, break_minutes: int}|null>  $dayOverrides
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $workStyleId,
        public readonly string $from,
        public readonly string $to,
        public readonly array $weeklyPattern,
        public readonly array $dayOverrides,
        public readonly string $overwriteMode,
        public readonly string $generatedByUserId,
    ) {}
}
