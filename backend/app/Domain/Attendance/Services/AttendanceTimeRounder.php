<?php

namespace App\Domain\Attendance\Services;

use App\Models\WorkStyle;
use Illuminate\Support\Carbon;

/**
 * 働き方(work_styles.rounding_unit_minutes・rounding_mode)に基づく時刻の丸め処理。
 * 日次入力の初期値提案(AttendanceDayDefaultsResolver)と、打刻からの日次実績反映
 * (AttendanceDayPunchSyncer)の両方から呼ばれる、丸め処理の唯一の実装箇所
 * (docs/03-architecture.md 3.5節: 経路ごとに計算ロジックを複製しない)。
 */
class AttendanceTimeRounder
{
    /**
     * 勤務区間が「始まる」打刻(始業・休憩終了)を丸める。$roundingModeが
     * shorten(勤務時間が短くなる方向)なら繰り上げ(遅い方へ)、lengthen(長くなる方向)なら
     * 繰り下げ(早い方へ)、nearestなら四捨五入する。
     */
    public function roundSegmentStart(Carbon $time, int $unitMinutes, string $roundingMode): Carbon
    {
        return match ($roundingMode) {
            WorkStyle::ROUNDING_MODE_SHORTEN => $this->ceilToUnit($time, $unitMinutes),
            WorkStyle::ROUNDING_MODE_LENGTHEN => $this->floorToUnit($time, $unitMinutes),
            default => $this->roundToNearest($time, $unitMinutes),
        };
    }

    /**
     * 勤務区間が「終わる」打刻(終業・休憩開始)を丸める。$roundingModeが
     * shorten(勤務時間が短くなる方向)なら繰り下げ(早い方へ)、lengthen(長くなる方向)なら
     * 繰り上げ(遅い方へ)、nearestなら四捨五入する。
     */
    public function roundSegmentEnd(Carbon $time, int $unitMinutes, string $roundingMode): Carbon
    {
        return match ($roundingMode) {
            WorkStyle::ROUNDING_MODE_SHORTEN => $this->floorToUnit($time, $unitMinutes),
            WorkStyle::ROUNDING_MODE_LENGTHEN => $this->ceilToUnit($time, $unitMinutes),
            default => $this->roundToNearest($time, $unitMinutes),
        };
    }

    /**
     * 打刻時刻を$unitMinutes分単位に最も近い時刻へ丸める(四捨五入)。$unitMinutesが1以下の
     * 場合は丸めない。
     */
    private function roundToNearest(Carbon $time, int $unitMinutes): Carbon
    {
        if ($unitMinutes <= 1) {
            return $time->copy();
        }

        $roundedMinutes = (int) round($this->minutesSinceMidnight($time) / $unitMinutes) * $unitMinutes;

        return $time->copy()->startOfDay()->addMinutes($roundedMinutes);
    }

    /** 打刻時刻を$unitMinutes分単位の直前(早い方)へ切り捨てる。 */
    private function floorToUnit(Carbon $time, int $unitMinutes): Carbon
    {
        if ($unitMinutes <= 1) {
            return $time->copy();
        }

        $roundedMinutes = (int) floor($this->minutesSinceMidnight($time) / $unitMinutes) * $unitMinutes;

        return $time->copy()->startOfDay()->addMinutes($roundedMinutes);
    }

    /** 打刻時刻を$unitMinutes分単位の直後(遅い方)へ切り上げる。 */
    private function ceilToUnit(Carbon $time, int $unitMinutes): Carbon
    {
        if ($unitMinutes <= 1) {
            return $time->copy();
        }

        $roundedMinutes = (int) ceil($this->minutesSinceMidnight($time) / $unitMinutes) * $unitMinutes;

        return $time->copy()->startOfDay()->addMinutes($roundedMinutes);
    }

    private function minutesSinceMidnight(Carbon $time): float
    {
        return $time->hour * 60 + $time->minute + ($time->second / 60);
    }
}
