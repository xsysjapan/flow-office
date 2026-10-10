<?php

namespace App\Models;

/**
 * attendance_days.source: どの経路で actual_start_at/actual_end_at/breaks が
 * 最後に確定したか。打刻ログ(AttendancePunch)による自動反映は PUNCH の日にのみ行う。
 */
final class AttendanceDaySource
{
    public const LIVE = 'live';

    public const MANUAL = 'manual';

    public const PUNCH = 'punch';

    /** 休暇の申請・承認に伴い勤怠日が作られた日(休暇だけの日。論点3)。打刻では PUNCH と同じく上書きできる。 */
    public const LEAVE = 'leave';
}
