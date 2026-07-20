<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\ClockOut;
use App\Domain\Attendance\Events\AttendanceBreakAutoInserted;
use App\Domain\Attendance\Events\AttendanceClockedOut;
use App\Domain\Attendance\Events\AttendanceDayCalculated;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\Attendance\Services\LiveAttendancePunchRecorder;
use App\Domain\Attendance\Services\WorkStyleFallbackResolver;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Models\User;
use App\Models\WorkStyle;
use App\Support\LocalDateTime;
use Illuminate\Support\Carbon;

/**
 * UC-A004: 退勤する。「今日」の判定と記録する時刻は、社員本人のタイムゾーンを基準にする。
 *
 * @implements CommandHandler<ClockOut>
 */
class ClockOutHandler implements CommandHandler
{
    /** 標準休憩の自動補完を検討する最短実働時間(6時間)。 */
    private const AUTO_BREAK_MINIMUM_WORK_MINUTES = 360;

    public function __construct(
        private readonly EventStore $eventStore,
        private readonly AttendanceCalculator $calculator,
        private readonly LiveAttendancePunchRecorder $punchRecorder,
        private readonly WorkStyleFallbackResolver $workStyleFallbackResolver,
    ) {}

    public function handle(Command $command): AttendanceDay
    {
        assert($command instanceof ClockOut);

        $user = User::query()->findOrFail($command->userId);

        $day = AttendanceDay::query()
            ->where('user_id', $command->userId)
            ->whereDate('work_date', Carbon::today($user->timezone)->toDateString())
            ->first();

        if ($day === null || $day->status !== AttendanceDayStatus::WORKING) {
            throw new DomainRuleException('勤務中の場合のみ退勤できます(休憩中は休憩終了後に退勤してください)。');
        }

        $day->actual_end_at = LocalDateTime::now($user->timezone);
        $day->status = AttendanceDayStatus::CLOCKED_OUT;
        $day->save();

        $this->punchRecorder->record($day, 'clock_out', $day->actual_end_at);

        $this->eventStore->append(
            aggregateType: 'attendance_day',
            aggregateId: (string) $day->id,
            event: new AttendanceClockedOut(
                attendanceDayId: $day->id,
                actualEndAt: LocalDateTime::formatWithOffsetMinutes($day->actual_end_at, $day->utc_offset_minutes),
            ),
        );

        $this->autoInsertStandardBreakIfApplicable($day);

        $calculation = $this->calculator->calculate($day->refresh()->load('breaks', 'leaveSegments', 'paidLeaveUsages', 'specialLeaveUsages', 'shiftAssignment.workStyle'));

        $this->eventStore->append(
            aggregateType: 'attendance_day',
            aggregateId: (string) $day->id,
            event: new AttendanceDayCalculated(
                attendanceDayId: $day->id,
                calculation: $calculation,
            ),
        );

        return $day;
    }

    /**
     * 指示書: 退勤時、働き方(work_styles.auto_break_enabled)が有効で、その日にまだ休憩が
     * 1件も記録されていない場合に限り、標準休憩(default_break_start_time〜
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
