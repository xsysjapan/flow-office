<?php

namespace App\Domain\CompensatoryLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 勤怠日の休日出勤の計算結果から、代休の下書き付与を同期する(作成・更新・削除)。
 * 勤怠の計算イベント(attendance_day.calculated・daily_calculation_adjusted・deleted)を受けるReactorが発行する。
 * 勤怠日テーブルは読まず、計算イベントの内容(利用者・勤務日・休日区分・実労働分)だけで判定する。
 *
 * Reactorが発行するため`viaReactor=true`・`initiatedByUserId`(連鎖の起点の操作者)を持つ。同じイベントを2回処理しても
 * 結果は同じ(口座集約は、同じ内容の下書き付与があれば何も記録しない)。
 */
class SyncCompensatoryLeaveAccountGrant implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $workDate,
        public readonly bool $isHolidayDay,
        public readonly int $workMinutes,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {}
}
