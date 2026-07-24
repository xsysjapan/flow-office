<?php

namespace App\Domain\Attendance\Services;

/**
 * 打刻ログ(参考情報)から1日分の勤務としてどこまで組み立てられるかの判定結果
 * (`AttendancePunchReconciler::classify()`)。打刻ログ自体はこの判定によらず常に記録される
 * (UC-A012)。この判定はあくまで`attendance_days`へどう反映するかを決めるためのものであり、
 * 矛盾を解消して最終的な実績を確定させるのは日次編集(UC-A005)でのユーザー自身の役割とする。
 */
enum PunchLogOutcome
{
    /** 出勤〜退勤まで(休憩含む)矛盾なく組み立てられる。日次実績として確定できる。 */
    case Complete;

    /** まだ退勤していない(または休憩中)が、ここまでの打刻に矛盾はない。現在の状態だけ反映する。 */
    case InProgress;

    /**
     * 出勤・退勤の重複や順序の矛盾など、打刻ログだけでは1日分の勤務を組み立てられない。
     * `attendance_days`は更新せず、ログに警告を残す(最終判断はユーザーに委ねる)。
     */
    case Contradictory;
}
