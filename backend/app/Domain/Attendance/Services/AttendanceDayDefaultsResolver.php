<?php

namespace App\Domain\Attendance\Services;

use App\Models\AttendancePunch;
use App\Models\EmployeeShiftAssignment;
use App\Models\PunchStatus;
use App\Models\PunchType;
use App\Models\SystemSetting;
use App\Models\WorkStyle;
use App\Support\LocalDateTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 日次勤怠の入力画面(未入力の日)を開いた際の初期値を、優先順位に沿って解決する。
 *
 * 1. 打刻(attendance_punches)があれば、その内容を働き方の丸め単位・丸め方向(work_styles.
 *    rounding_unit_minutes・rounding_mode)で丸めて反映する。
 * 2. 打刻が無く勤務予定(employee_shift_assignments)があれば、その予定(休憩を含む)を表示する。
 * 3. 勤務予定も無ければ、システムの初期設定(その月に割り当てられた働き方 → 会社の
 *    デフォルト働き方の標準時刻・標準休憩)を表示する(WorkStyleFallbackResolver参照)。
 *
 * ここで返す値はあくまで入力欄への「提案」であり、保存されるまでは正データを変更しない
 * (docs/03-architecture.md 3.2節)。
 */
class AttendanceDayDefaultsResolver
{
    public function __construct(private readonly WorkStyleFallbackResolver $workStyleFallbackResolver) {}

    /**
     * @return array{source: string, actual_start_at: ?string, actual_end_at: ?string, breaks: array<int, array{start: string, end: ?string}>}
     */
    public function resolve(string $userId, string $workDate): array
    {
        $workDateCarbon = Carbon::parse($workDate);

        $punches = AttendancePunch::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', $workDate)
            ->where('status', PunchStatus::ACTIVE)
            ->orderBy('punched_at')
            ->get();

        if ($punches->isNotEmpty()) {
            return $this->resolveFromPunches($userId, $workDateCarbon, $punches);
        }

        $shift = EmployeeShiftAssignment::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', $workDate)
            ->first();

        if ($shift !== null && ($shift->planned_start_at !== null || $shift->planned_end_at !== null)) {
            return $this->resolveFromShift($shift, $workDateCarbon);
        }

        return $this->resolveFromSystemDefault($userId, $workDateCarbon);
    }

    /**
     * @param  Collection<int, AttendancePunch>  $punches
     * @return array{source: string, actual_start_at: ?string, actual_end_at: ?string, breaks: array<int, array{start: string, end: ?string}>}
     */
    private function resolveFromPunches(string $userId, Carbon $workDate, $punches): array
    {
        $shift = EmployeeShiftAssignment::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', $workDate->toDateString())
            ->first();

        $workStyle = $shift?->workStyle ?? $this->workStyleFallbackResolver->resolveForUser($userId, $workDate);
        $roundingUnitMinutes = $workStyle?->rounding_unit_minutes ?: 1;
        $roundingMode = $workStyle?->rounding_mode ?? WorkStyle::ROUNDING_MODE_NEAREST;
        $offsetMinutes = $punches->first()->utc_offset_minutes;

        $clockIn = $punches->firstWhere('punch_type', PunchType::CLOCK_IN);
        $clockOut = $punches->last(fn (AttendancePunch $punch) => $punch->punch_type === PunchType::CLOCK_OUT);

        $breaks = [];
        $openBreakStart = null;
        foreach ($punches as $punch) {
            if ($punch->punch_type === PunchType::BREAK_START) {
                $openBreakStart = $punch->punched_at;
            } elseif ($punch->punch_type === PunchType::BREAK_END && $openBreakStart !== null) {
                $breaks[] = [
                    'start' => LocalDateTime::formatWithOffsetMinutes($this->roundSegmentEnd($openBreakStart, $roundingUnitMinutes, $roundingMode), $offsetMinutes),
                    'end' => LocalDateTime::formatWithOffsetMinutes($this->roundSegmentStart($punch->punched_at, $roundingUnitMinutes, $roundingMode), $offsetMinutes),
                ];
                $openBreakStart = null;
            }
        }

        return [
            'source' => 'punch',
            'actual_start_at' => $clockIn !== null
                ? LocalDateTime::formatWithOffsetMinutes($this->roundSegmentStart($clockIn->punched_at, $roundingUnitMinutes, $roundingMode), $offsetMinutes)
                : null,
            'actual_end_at' => $clockOut !== null
                ? LocalDateTime::formatWithOffsetMinutes($this->roundSegmentEnd($clockOut->punched_at, $roundingUnitMinutes, $roundingMode), $offsetMinutes)
                : null,
            'breaks' => $breaks,
        ];
    }

    /**
     * @return array{source: string, actual_start_at: ?string, actual_end_at: ?string, breaks: array<int, array{start: string, end: ?string}>}
     */
    private function resolveFromShift(EmployeeShiftAssignment $shift, Carbon $workDate): array
    {
        $timezone = $this->defaultTimezone();

        $breaks = [];
        if ($shift->planned_break_start_at !== null && $shift->planned_break_end_at !== null) {
            $breaks[] = [
                'start' => LocalDateTime::toIso8601($shift->planned_break_start_at, $timezone),
                'end' => LocalDateTime::toIso8601($shift->planned_break_end_at, $timezone),
            ];
        }

        return [
            'source' => 'schedule',
            'actual_start_at' => LocalDateTime::toIso8601($shift->planned_start_at, $timezone),
            'actual_end_at' => LocalDateTime::toIso8601($shift->planned_end_at, $timezone),
            'breaks' => $breaks,
        ];
    }

    /**
     * @return array{source: string, actual_start_at: ?string, actual_end_at: ?string, breaks: array<int, array{start: string, end: ?string}>}
     */
    private function resolveFromSystemDefault(string $userId, Carbon $workDate): array
    {
        $workStyle = $this->workStyleFallbackResolver->resolveForUser($userId, $workDate);

        if ($workStyle === null) {
            return ['source' => 'none', 'actual_start_at' => null, 'actual_end_at' => null, 'breaks' => []];
        }

        $timezone = $this->defaultTimezone();

        $startAt = $workStyle->default_start_time !== null ? $workDate->copy()->setTimeFromTimeString($workStyle->default_start_time) : null;
        $endAt = $workStyle->default_end_time !== null ? $workDate->copy()->setTimeFromTimeString($workStyle->default_end_time) : null;

        $breaks = [];
        if ($workStyle->default_break_start_time !== null && $workStyle->default_break_end_time !== null) {
            $breaks[] = [
                'start' => LocalDateTime::toIso8601($workDate->copy()->setTimeFromTimeString($workStyle->default_break_start_time), $timezone),
                'end' => LocalDateTime::toIso8601($workDate->copy()->setTimeFromTimeString($workStyle->default_break_end_time), $timezone),
            ];
        }

        return [
            'source' => 'system_default',
            'actual_start_at' => LocalDateTime::toIso8601($startAt, $timezone),
            'actual_end_at' => LocalDateTime::toIso8601($endAt, $timezone),
            'breaks' => $breaks,
        ];
    }

    private function defaultTimezone(): string
    {
        return SystemSetting::current()->default_timezone;
    }

    /**
     * 勤務区間が「始まる」打刻(始業・休憩終了)を丸める。$roundingModeが
     * shorten(勤務時間が短くなる方向)なら繰り上げ(遅い方へ)、lengthen(長くなる方向)なら
     * 繰り下げ(早い方へ)、nearestなら四捨五入する。
     */
    private function roundSegmentStart(Carbon $time, int $unitMinutes, string $roundingMode): Carbon
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
    private function roundSegmentEnd(Carbon $time, int $unitMinutes, string $roundingMode): Carbon
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
