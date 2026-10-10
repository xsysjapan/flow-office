<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 休暇の承認を勤怠へ反映する(承認時に日次計算を記録し直す。休暇申請文脈のイベントから発行するReactorのCommand)。
 *
 * 対象日は休暇ビュー(attendance_day_leaves)の行から読む(承認イベントは対象日を持たないため)。
 * 処理内容: (a) 締め判定、(b) 勤怠日が無ければ作成(通常は申請時に作成済み)、(c) 日次計算の記録。
 * 再実行しても結果は同じ(日次計算は毎回記録してよい)。
 */
class RecalculateAttendanceDayForLeave implements Command
{
    public function __construct(
        public readonly string $leaveKind,
        public readonly string $leaveRequestId,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {}
}
