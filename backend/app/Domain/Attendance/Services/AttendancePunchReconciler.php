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
 * - 全ての打刻が同一のUTCオフセット(分)で記録されている(異なるオフセットが混在する場合、
 *   壁時計時刻どうしの前後比較に意味がなくなるため矛盾ありとする)
 * - clock_in がちょうど1件で、clock_out があれば(まだ退勤していない場合はまだ無くてよい)
 *   ちょうど1件、かつ clock_in 以降であること(同一秒に複数の打刻が記録される場合を許容する
 *   ため、前後関係は「以降(同時刻を含む)」で判定し、厳密に「より後」までは要求しない)
 * - clock_in/clock_out 以外(休憩)の打刻が break_start → break_end の順で並んでいる
 *   (まだ退勤していない場合、末尾の休憩開始が対になっていない=現在休憩中であることは許容する)
 * - 各休憩は clock_in 〜 clock_out(またはそれまでの打刻)の範囲内に収まり、
 *   休憩どうしが重複・逆順しない
 *
 * これ以外(出勤・退勤の重複、休憩の順序不整合など)は矛盾ありと判定する。矛盾がある場合でも
 * 打刻ログ自体は必ず記録される(UC-A012)。矛盾の解消はUC-A005の日次編集でユーザー自身が行う。
 */
class AttendancePunchReconciler
{
    /**
     * @param  Collection<int, AttendancePunch>  $punches  同一user_id・work_dateのpunched_at昇順の打刻一覧(1件以上)
     */
    public function classify(Collection $punches): PunchReconciliationResult
    {
        $distinctOffsets = $punches->pluck('utc_offset_minutes')->unique();
        if ($distinctOffsets->count() > 1) {
            return PunchReconciliationResult::contradictory('異なるタイムゾーンオフセットの打刻が混在しています。');
        }
        $utcOffsetMinutes = $distinctOffsets->first();

        $clockIns = $punches->where('punch_type', PunchType::CLOCK_IN)->values();
        $clockOuts = $punches->where('punch_type', PunchType::CLOCK_OUT)->values();

        if ($clockIns->count() > 1) {
            return PunchReconciliationResult::contradictory('出勤の打刻が複数あります。');
        }
        if ($clockOuts->count() > 1) {
            return PunchReconciliationResult::contradictory('退勤の打刻が複数あります。');
        }
        if ($clockIns->isEmpty()) {
            return PunchReconciliationResult::contradictory('出勤の打刻がないまま他の打刻が記録されています。');
        }

        $clockIn = $clockIns->first()->punched_at;
        $breakEvents = $punches
            ->reject(fn (AttendancePunch $punch) => in_array($punch->punch_type, [PunchType::CLOCK_IN, PunchType::CLOCK_OUT], true))
            ->values();

        if ($clockOuts->isEmpty()) {
            // まだ退勤していない: ここまでの打刻に矛盾がなければ、現在の状態(勤務中/休憩中)
            // だけを反映する(休憩開始が対になっていない=現在休憩中、は許容する)。
            if (! $this->breaksAreWellOrdered($breakEvents, $clockIn, clockOut: null)) {
                return PunchReconciliationResult::contradictory('休憩の打刻の順序が矛盾しています。');
            }

            return PunchReconciliationResult::inProgress();
        }

        $clockOut = $clockOuts->first()->punched_at;
        if ($clockOut->lessThan($clockIn)) {
            return PunchReconciliationResult::contradictory('退勤が出勤より前になっています。');
        }

        if ($breakEvents->count() % 2 !== 0) {
            return PunchReconciliationResult::contradictory('休憩の開始・終了が対になっていません。');
        }

        if (! $this->breaksAreWellOrdered($breakEvents, $clockIn, $clockOut)) {
            return PunchReconciliationResult::contradictory('休憩の打刻の順序が矛盾しています。');
        }

        $breaks = [];
        for ($i = 0; $i < $breakEvents->count(); $i += 2) {
            $breaks[] = ['start' => $breakEvents[$i]->punched_at, 'end' => $breakEvents[$i + 1]->punched_at];
        }

        return PunchReconciliationResult::complete([
            'clock_in' => $clockIn,
            'clock_out' => $clockOut,
            'breaks' => $breaks,
            'utc_offset_minutes' => $utcOffsetMinutes,
        ]);
    }

    /**
     * 休憩イベント列(punched_at昇順)が、開始→終了の順で交互に並び、出勤〜退勤(退勤前の
     * 場合は現在まで)の範囲内に収まっているかを判定する。$clockOutがnull(まだ退勤していない)
     * の場合に限り、末尾が対になっていない休憩開始(現在休憩中)であることを許容する。
     *
     * @param  Collection<int, AttendancePunch>  $breakEvents
     */
    private function breaksAreWellOrdered(Collection $breakEvents, Carbon $clockIn, ?Carbon $clockOut): bool
    {
        $count = $breakEvents->count();
        $hasTrailingOpenBreak = $clockOut === null && $count % 2 === 1;

        if (! $hasTrailingOpenBreak && $count % 2 !== 0) {
            return false;
        }

        $pairedCount = $hasTrailingOpenBreak ? $count - 1 : $count;
        $previousEnd = $clockIn;

        for ($i = 0; $i < $pairedCount; $i += 2) {
            $start = $breakEvents[$i];
            $end = $breakEvents[$i + 1];

            if ($start->punch_type !== PunchType::BREAK_START || $end->punch_type !== PunchType::BREAK_END) {
                return false;
            }
            if ($start->punched_at->lessThan($previousEnd)) {
                return false;
            }
            if ($end->punched_at->lessThan($start->punched_at)) {
                return false;
            }
            if ($clockOut !== null && $end->punched_at->greaterThan($clockOut)) {
                return false;
            }

            $previousEnd = $end->punched_at;
        }

        if ($hasTrailingOpenBreak) {
            $openStart = $breakEvents[$count - 1];
            if ($openStart->punch_type !== PunchType::BREAK_START || $openStart->punched_at->lessThan($previousEnd)) {
                return false;
            }
        }

        return true;
    }
}
