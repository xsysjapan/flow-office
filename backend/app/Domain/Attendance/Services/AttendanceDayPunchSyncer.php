<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Events\AttendanceBreakAutoInserted;
use App\Domain\Attendance\Events\AttendanceDayCalculated;
use App\Domain\Attendance\Events\AttendanceDayLiveStatusSynced;
use App\Domain\Attendance\Events\AttendanceDaySyncedFromPunches;
use App\Domain\EventSourcing\EventStore;
use App\Models\AttendanceDay;
use App\Models\AttendanceDaySource;
use App\Models\AttendanceDayStatus;
use App\Models\AttendancePunch;
use App\Models\EmployeeShiftAssignment;
use App\Models\PunchStatus;
use App\Models\PunchType;
use App\Models\WorkStyle;
use App\Support\LocalDateTime;
use Illuminate\Support\Collection;

/**
 * UC-A001〜A004・UC-A012〜UC-A014: 有効な打刻ログ(`status=active`)を集めて、矛盾なく
 * 1日分の勤務として組み立てられる場合のみ attendance_days / attendance_breaks に反映する。
 * Web画面の出退勤操作(`WebPunchDispatcher`経由)・共有端末・個人端末のいずれからの打刻も、
 * 記録・訂正・削除の後に必ずこの1つの規則を通る(経路ごとに計算ロジックを複製しない。
 * docs/03-architecture.md 3.5)。
 *
 * 1日分として矛盾なく組み立てられない間(出勤のみ・休憩開始のみ等)も、最新の打刻から
 * `attendance_days.status`だけは反映する(社員本人・管理者が「今の状態」を見て取れるように
 * するため)。ただし既に退勤済みの日は、以降の打刻では状態を変えない
 * (矛盾の解消はUC-A005の日次編集で行う)。
 */
class AttendanceDayPunchSyncer
{
    /** 標準休憩の自動補完を検討する最短実働時間(6時間)。 */
    private const AUTO_BREAK_MINIMUM_WORK_MINUTES = 360;

    public function __construct(
        private readonly EventStore $eventStore,
        private readonly AttendancePunchReconciler $reconciler,
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceEditGuard $guard,
        private readonly WorkStyleFallbackResolver $workStyleFallbackResolver,
    ) {}

    public function sync(string $userId, string $workDate): void
    {
        $punches = AttendancePunch::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', $workDate)
            ->where('status', PunchStatus::ACTIVE)
            ->orderBy('punched_at')
            ->with('device')
            ->get();

        $day = AttendanceDay::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', $workDate)
            ->first();

        if ($day !== null && $day->source !== AttendanceDaySource::PUNCH) {
            // 画面からの操作・日次編集で既に確定した日は、打刻ログで上書きしない。
            return;
        }

        if ($day !== null && $day->status === AttendanceDayStatus::CLOCKED_OUT) {
            // 既に退勤済みの日は、以降の打刻ログでは状態を変えない。
            return;
        }

        if (! $this->guard->isMutable($day, $userId, $workDate)) {
            // 締め後にロック済み、または承認済み・締め済みの月に属する日は、
            // 打刻の記録・訂正・削除では上書きしない(修正申請ワークフローを使う)。
            return;
        }

        $reconciled = $this->reconciler->reconcile($punches);
        if ($reconciled === null) {
            $this->syncLiveStatus($day, $userId, $workDate, $punches);

            return;
        }

        if ($day === null) {
            $day = $this->findOrCreateDay($userId, $workDate);
        }

        $day->actual_start_at = $reconciled['clock_in'];
        $day->actual_end_at = $reconciled['clock_out'];
        $day->utc_offset_minutes = $reconciled['utc_offset_minutes'];
        $day->status = AttendanceDayStatus::CLOCKED_OUT;
        $day->source = AttendanceDaySource::PUNCH;

        // 打刻に使われた端末に既定の勤務形態区分が設定されていれば反映する
        // (docs/07-usecases-attendance.md「勤務形態区分」)。どの端末で打刻したか分からない
        // 場合は既存の値を保持する(勝手にクリアしない)。
        $workLocationType = $punches
            ->whereNotNull('device_id')
            ->reverse()
            ->map(fn (AttendancePunch $punch) => $punch->device?->default_work_location_type)
            ->first(fn (?string $value) => $value !== null);

        if ($workLocationType !== null) {
            $day->work_location_type = $workLocationType;
        }

        $day->save();

        $day->breaks()->delete();
        foreach ($reconciled['breaks'] as $break) {
            $day->breaks()->create([
                'break_start_at' => $break['start'],
                'break_end_at' => $break['end'],
            ]);
        }

        $this->autoInsertStandardBreakIfApplicable($day);

        $this->eventStore->append(
            aggregateType: 'attendance_day',
            aggregateId: (string) $day->id,
            event: new AttendanceDaySyncedFromPunches(
                attendanceDayId: $day->id,
                actualStartAt: LocalDateTime::formatWithOffsetMinutes($day->actual_start_at, $day->utc_offset_minutes),
                actualEndAt: LocalDateTime::formatWithOffsetMinutes($day->actual_end_at, $day->utc_offset_minutes),
            ),
        );

        $calculation = $this->calculator->calculate($day->refresh()->load('breaks', 'leaveSegments', 'paidLeaveUsages', 'specialLeaveUsages', 'shiftAssignment.workStyle'));

        $this->eventStore->append(
            aggregateType: 'attendance_day',
            aggregateId: (string) $day->id,
            event: new AttendanceDayCalculated(
                attendanceDayId: $day->id,
                calculation: $calculation,
            ),
        );
    }

    /**
     * 1日分として矛盾なく組み立てられない間、最新の有効な打刻から`status`だけを反映する。
     * 出勤・休憩終了は「勤務中」、休憩開始は「休憩中」に対応させる。単独の退勤打刻は
     * (出勤打刻との対応が取れていないため)状態を変えない。
     *
     * @param  Collection<int, AttendancePunch>  $punches  同一user_id・work_dateのpunched_at昇順の打刻一覧
     */
    private function syncLiveStatus(?AttendanceDay $day, string $userId, string $workDate, Collection $punches): void
    {
        $latestPunch = $punches->last();
        if ($latestPunch === null) {
            return;
        }

        $status = match ($latestPunch->punch_type) {
            PunchType::CLOCK_IN, PunchType::BREAK_END => AttendanceDayStatus::WORKING,
            PunchType::BREAK_START => AttendanceDayStatus::ON_BREAK,
            default => null,
        };

        if ($status === null) {
            return;
        }

        if ($day === null) {
            $day = $this->findOrCreateDay($userId, $workDate);
        }

        if ($day->status === $status) {
            return;
        }

        $day->status = $status;
        $day->source = AttendanceDaySource::PUNCH;

        if ($status === AttendanceDayStatus::WORKING && $day->actual_start_at === null) {
            $firstClockIn = $punches->firstWhere('punch_type', PunchType::CLOCK_IN);
            if ($firstClockIn !== null) {
                $day->actual_start_at = $firstClockIn->punched_at;
                $day->utc_offset_minutes = $firstClockIn->utc_offset_minutes;
            }
        }

        $day->save();

        $this->eventStore->append(
            aggregateType: 'attendance_day',
            aggregateId: (string) $day->id,
            event: new AttendanceDayLiveStatusSynced(
                attendanceDayId: $day->id,
                status: $status,
            ),
        );
    }

    private function findOrCreateDay(string $userId, string $workDate): AttendanceDay
    {
        $shiftAssignment = EmployeeShiftAssignment::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', $workDate)
            ->first();

        return AttendanceDay::query()->create([
            'user_id' => $userId,
            'work_date' => $workDate,
            'shift_assignment_id' => $shiftAssignment?->id,
            'status' => AttendanceDayStatus::NOT_STARTED,
            'source' => AttendanceDaySource::PUNCH,
        ]);
    }

    /**
     * 指示書: 1日分の勤務が確定した際、働き方(work_styles.auto_break_enabled)が有効で、
     * その日にまだ休憩が1件も記録されていない場合に限り、標準休憩(default_break_start_time〜
     * default_break_end_time)を自動でattendance_breaksへ補完する。実際に打刻・編集された
     * 休憩が1件でもあれば何もしない(上書き・重複させない)。
     *
     * 適用条件(.claude/skills/attendance-calc-review参照。いずれも所定労働時間・休憩時刻の
     * マスタ設定のみを根拠にし、8時間等の法定値をここでハードコードしない):
     * - 対象日の働き方でauto_break_enabledが有効
     * - 働き方にdefault_break_start_time・default_break_end_timeが両方設定されている
     * - 実働時間(出勤〜退勤)が6時間以上
     * - 標準休憩の時間帯が実働時間内に完全に収まる
     * - その日に休憩が1件も記録されていない
     */
    private function autoInsertStandardBreakIfApplicable(AttendanceDay $day): void
    {
        if ($day->breaks()->count() > 0) {
            return;
        }

        $start = $day->actual_start_at;
        $end = $day->actual_end_at;
        if ($start === null || $end === null) {
            return;
        }

        if ($start->diffInMinutes($end) < self::AUTO_BREAK_MINIMUM_WORK_MINUTES) {
            return;
        }

        $day->loadMissing('shiftAssignment.workStyle');
        $workStyle = $day->shiftAssignment?->workStyle
            ?? $this->workStyleFallbackResolver->resolveForUser($day->user_id, $day->work_date->copy());

        if (! $this->supportsAutoBreak($workStyle)) {
            return;
        }

        $breakStart = $day->work_date->copy()->setTimeFromTimeString($workStyle->default_break_start_time);
        $breakEnd = $day->work_date->copy()->setTimeFromTimeString($workStyle->default_break_end_time);

        if ($breakEnd->lessThanOrEqualTo($breakStart)) {
            return;
        }

        if (! ($start->lessThanOrEqualTo($breakStart) && $breakEnd->lessThanOrEqualTo($end))) {
            return;
        }

        $break = $day->breaks()->create([
            'break_start_at' => $breakStart,
            'break_end_at' => $breakEnd,
        ]);

        $this->eventStore->append(
            aggregateType: 'attendance_day',
            aggregateId: (string) $day->id,
            event: new AttendanceBreakAutoInserted(
                attendanceDayId: $day->id,
                attendanceBreakId: $break->id,
                workStyleId: $workStyle->id,
                breakStartAt: LocalDateTime::formatWithOffsetMinutes($breakStart, $day->utc_offset_minutes),
                breakEndAt: LocalDateTime::formatWithOffsetMinutes($breakEnd, $day->utc_offset_minutes),
            ),
        );
    }

    private function supportsAutoBreak(?WorkStyle $workStyle): bool
    {
        return $workStyle !== null
            && $workStyle->auto_break_enabled
            && $workStyle->default_break_start_time !== null
            && $workStyle->default_break_end_time !== null;
    }
}
