<?php

namespace App\Domain\Attendance\Services;

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
use App\Support\LocalDateTime;
use Illuminate\Support\Collection;

/**
 * UC-A012〜UC-A014: 有効な打刻ログ(`status=active`)を集めて、矛盾なく1日分の勤務として
 * 組み立てられる場合のみ attendance_days / attendance_breaks に反映する。打刻の記録・訂正・
 * 削除のいずれの後にも同じ規則で呼び出される。
 *
 * 1日分として矛盾なく組み立てられない間(出勤のみ・休憩開始のみ等)も、画面の出退勤操作と
 * 同様に最新の打刻から`attendance_days.status`だけは反映する(社員本人・管理者が「今の状態」を
 * 見て取れるようにするため)。ただし既に退勤済みの日は、以降の打刻では状態を変えない
 * (矛盾の解消はUC-A005の日次編集で行う)。
 */
class AttendanceDayPunchSyncer
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly AttendancePunchReconciler $reconciler,
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceEditGuard $guard,
    ) {}

    public function sync(int $userId, string $workDate): void
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
    private function syncLiveStatus(?AttendanceDay $day, int $userId, string $workDate, Collection $punches): void
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

    private function findOrCreateDay(int $userId, string $workDate): AttendanceDay
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
}
