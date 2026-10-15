<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 休暇の申請・再提出を勤怠へ反映する(休暇申請文脈のイベントから発行するReactorのCommand)。
 *
 * 休暇の内容(取得単位・時間数)は休暇ビュー(attendance_day_leaves)の行から読む(Projectorは
 * Reactorより先に処理される)。処理内容: (a) 締め判定、(b) 同じ日の有効な休暇との衝突判定
 * (今回の休暇自身は除く。違反なら例外)、(c) 勤怠日が無ければ source=leave で作成、(d) 日次計算の記録。
 *
 * viaReactor=true のとき、勤怠日が既にあれば作成しない(同じ休暇を2回処理しても結果は同じ)。
 * initiatedByUserId は連鎖の起点の操作者(申請者・再提出者)。
 */
class ApplyLeaveToAttendanceDay implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $workDate,
        public readonly string $leaveKind,
        public readonly string $leaveRequestId,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {}
}
