<?php

namespace App\Domain\CompensatoryLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 勤怠実績(日次計算)の反映・削除を受けて、対象日の代休Grant(draft)を同期する。
 * 削除イベントの場合もattendanceDayIdは同じ(既に削除された行のID)を渡す。
 */
class SyncCompensatoryLeaveGrant implements Command
{
    public function __construct(
        public readonly string $attendanceDayId,
    ) {}
}
