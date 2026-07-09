<?php

namespace App\Domain\Attendance\Services;

use App\Models\AttendancePunch;
use App\Models\PunchType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 打刻ログ(参考情報)から、矛盾のない1日分の勤務(出勤・退勤・休憩)を組み立てられるか判定する。
 *
 * 「矛盾がない」とは以下をすべて満たすことを言う:
 * - clock_in がちょうど1件、clock_out がちょうど1件で、clock_out が clock_in より後
 * - clock_in/clock_out 以外(休憩)の打刻が break_start → break_end の順で偶数件並んでいる
 * - 各休憩は clock_in 〜 clock_out の範囲内に収まり、休憩どうしが重複・逆順しない
 *
 * これ以外(打刻漏れ・重複・順序不整合など)は矛盾ありと判定し、呼び出し側は
 * attendance_days を更新しない(打刻はあくまで参考情報であり、日次の記録が正)。
 */
class AttendancePunchReconciler
{
    /**
     * @param  Collection<int, AttendancePunch>  $punches  同一user_id・work_dateのpunched_at昇順の打刻一覧
     * @return array{clock_in: Carbon, clock_out: Carbon, breaks: array<int, array{start: Carbon, end: Carbon}>}|null
     */
    public function reconcile(Collection $punches): ?array
    {
        $clockIns = $punches->where('punch_type', PunchType::CLOCK_IN)->values();
        $clockOuts = $punches->where('punch_type', PunchType::CLOCK_OUT)->values();

        if ($clockIns->count() !== 1 || $clockOuts->count() !== 1) {
            return null;
        }

        $clockIn = $clockIns->first()->punched_at;
        $clockOut = $clockOuts->first()->punched_at;

        if ($clockOut->lessThanOrEqualTo($clockIn)) {
            return null;
        }

        $breakEvents = $punches
            ->reject(fn (AttendancePunch $punch) => in_array($punch->punch_type, [PunchType::CLOCK_IN, PunchType::CLOCK_OUT], true))
            ->values();

        if ($breakEvents->count() % 2 !== 0) {
            return null;
        }

        $breaks = [];
        $previousEnd = null;

        for ($i = 0; $i < $breakEvents->count(); $i += 2) {
            $start = $breakEvents[$i];
            $end = $breakEvents[$i + 1];

            if ($start->punch_type !== PunchType::BREAK_START || $end->punch_type !== PunchType::BREAK_END) {
                return null;
            }

            if ($start->punched_at->lessThan($clockIn) || $end->punched_at->greaterThan($clockOut)) {
                return null;
            }

            if ($end->punched_at->lessThanOrEqualTo($start->punched_at)) {
                return null;
            }

            if ($previousEnd !== null && $start->punched_at->lessThan($previousEnd)) {
                return null;
            }

            $breaks[] = ['start' => $start->punched_at, 'end' => $end->punched_at];
            $previousEnd = $end->punched_at;
        }

        return [
            'clock_in' => $clockIn,
            'clock_out' => $clockOut,
            'breaks' => $breaks,
        ];
    }
}
