<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Models\AttendanceDay;
use App\Models\AttendanceDaySource;
use App\Models\AttendanceDayStatus;
use App\Models\EmployeeCalendarEntry;
use App\Models\SystemSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 休暇の反映に伴う勤怠日の作成・日次計算の記録を行う(休暇用のReactor経由Commandから使う共通処理)。
 * 業務ルール(締め・衝突・削除条件)は各Handler・判定クラスが持ち、ここは記録の手順だけを持つ。
 */
class LeaveAttendanceDayRecorder
{
    public function __construct(
        private readonly AttendanceCalculator $calculator,
        private readonly EffectiveScheduleResolver $effectiveScheduleResolver,
        private readonly WorkStyleFallbackResolver $workStyleFallbackResolver,
    ) {}

    /**
     * その日の所定労働時間(分・フル)。勤務予定→その月の働き方→システムのデフォルト働き方の順で解決する
     * (AttendanceCalculatorの所定労働時間と同じ解決)。半休の調整は含めない(LeaveDayConflictPolicyが行う)。
     */
    public function prescribedMinutes(string $userId, string $workDate): int
    {
        $date = Carbon::parse($workDate);
        $entry = $this->calendarEntry($userId, $workDate);

        $shift = $this->effectiveScheduleResolver->resolve($userId, $date, $entry);
        $workStyle = $shift?->workStyle ?? $this->workStyleFallbackResolver->resolveForUser($userId, $date->copy());

        return $workStyle?->prescribed_daily_minutes ?? 0;
    }

    /**
     * 対象日が勤務予定日か。勤務予定→その月の働き方→システムのデフォルト働き方の順で解決する
     * (勤務予定が未展開の通常勤務でも判定できる)。解決できなければfalse。
     */
    public function isScheduledWorkingDay(string $userId, string $workDate): bool
    {
        $date = Carbon::parse($workDate);

        return (bool) $this->effectiveScheduleResolver
            ->resolve($userId, $date, $this->calendarEntry($userId, $workDate))?->is_working_day;
    }

    /**
     * 勤怠日が無ければ source=leave(status=not_started、実績なし)で作成し、あればそれを返す。
     */
    public function ensureDay(string $userId, string $workDate, string $createdByUserId): AttendanceDay
    {
        $existing = $this->dayOf($userId, $workDate);
        if ($existing !== null) {
            return $existing;
        }

        $dayId = (string) Str::uuid();

        AttendanceDayAggregate::retrieve($dayId)
            ->create(
                userId: $userId,
                workDate: $workDate,
                calendarEntryId: $this->calendarEntry($userId, $workDate)?->id,
                status: AttendanceDayStatus::NOT_STARTED,
                source: AttendanceDaySource::LEAVE,
                utcOffsetMinutes: $this->defaultUtcOffsetMinutes($workDate),
                actualStartAt: null,
                actualEndAt: null,
                workType: null,
                workLocationType: null,
                note: null,
                breaks: [],
                leaveSegments: [],
                reason: '休暇の申請・承認に伴い勤怠日を作成',
                createdByUserId: $createdByUserId,
            )
            ->persist();

        return AttendanceDay::query()->findOrFail($dayId);
    }

    /** 勤怠日の日次計算を記録する(AttendanceCalculatorは永続化後の実データから読む)。 */
    public function recalculate(AttendanceDay $day): void
    {
        $fresh = AttendanceDay::query()->findOrFail($day->id)
            ->load('breaks', 'leaveSegments', 'calendarEntry.workStyle');

        $calculation = $this->calculator->calculate($fresh);

        // 勤怠日の現在の日次計算(直近の計算・補正のイベント)と同じ結果なら記録しない(同じ休暇の反映を再処理しても
        // イベントが増えない・冪等)。比較は日次計算の全項目。
        if ($this->isSameAsCurrentCalculation($fresh->id, $calculation)) {
            return;
        }

        AttendanceDayAggregate::retrieve($fresh->id)->calculate($calculation, $fresh->user_id, $fresh->work_date->toDateString())->persist();
    }

    /**
     * 直近の計算系イベント(attendance_day.calculated・daily_calculation_adjusted)が計算イベントで、その計算結果が
     * 与えられた結果と全項目で同じか。補正イベントが直近なら(補正後の値は別物のため)falseを返す。
     *
     * @param  array<string, mixed>  $calculation
     */
    private function isSameAsCurrentCalculation(string $dayId, array $calculation): bool
    {
        $latest = DB::table('stored_events')
            ->where('aggregate_uuid', $dayId)
            ->whereIn('event_class', ['attendance_day.calculated', 'attendance_day.daily_calculation_adjusted'])
            ->orderByDesc('id')
            ->first();

        if ($latest === null || $latest->event_class !== 'attendance_day.calculated') {
            return false;
        }

        $recorded = json_decode((string) $latest->event_properties, true)['calculation'] ?? null;
        $new = json_decode(json_encode($calculation, JSON_THROW_ON_ERROR), true);

        return is_array($recorded) && $recorded == $new;
    }

    public function dayOf(string $userId, string $workDate): ?AttendanceDay
    {
        return AttendanceDay::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', $workDate)
            ->first();
    }

    private function calendarEntry(string $userId, string $workDate): ?EmployeeCalendarEntry
    {
        return EmployeeCalendarEntry::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', $workDate)
            ->first();
    }

    /** システムのデフォルトタイムゾーンのUTCオフセット(分)。打刻を伴わない勤怠日の作成に使う(CreateAttendanceDayHandlerと同じ)。 */
    private function defaultUtcOffsetMinutes(string $workDate): int
    {
        $defaultTimezone = SystemSetting::current()->default_timezone;

        return intdiv(Carbon::parse($workDate, $defaultTimezone)->getOffset(), 60);
    }
}
