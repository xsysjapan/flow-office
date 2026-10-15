<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 1日分の日次計算(attendance_day.calculated)を現在の計算ロジックで記録し直す。
 * 一括再計算コマンド(attendance:recalculate-days --apply)が日ごとに発行する。
 *
 * 手動調整済み(attendance_daily_calculations.is_manually_adjusted)の日は記録しない(DomainRuleException)。
 * 再実行しても結果は同じ(計算値が同じなら同じ内容のイベントを追記するだけ)。
 */
class RecalculateAttendanceDailyCalculation implements Command
{
    public function __construct(
        public readonly string $attendanceDayId,
    ) {}
}
