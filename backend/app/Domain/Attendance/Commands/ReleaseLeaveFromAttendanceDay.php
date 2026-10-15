<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 休暇の差戻し・取消を勤怠へ反映する(休暇申請文脈のイベントから発行するReactorのCommand)。
 *
 * 対象日は休暇ビュー(attendance_day_leaves)の行から読む(差戻し・取消イベントは対象日を持たない)。
 * 処理内容: (a) 締め判定、(b) 論点15の条件(LeaveReleaseDayPolicy)を満たせば勤怠日を削除
 * (attendance_day.deleted)、満たさなければ日次計算を記録する。
 *
 * viaReactor=true のとき、勤怠日が既に無ければ何もしない(冪等)。
 * initiatedByUserId は連鎖の起点の操作者(差戻し者・取消者)。
 */
class ReleaseLeaveFromAttendanceDay implements Command
{
    public function __construct(
        public readonly string $leaveKind,
        public readonly string $leaveRequestId,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {}
}
