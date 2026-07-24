<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Models\AttendanceBreak;
use App\Models\AttendanceDay;
use App\Models\AttendanceDaySource;
use App\Models\AttendanceDayStatus;
use App\Models\AttendanceLeaveSegment;
use App\Models\AttendancePunch;
use App\Models\EmployeeShiftAssignment;
use App\Models\PaidLeaveUsage;
use App\Models\PunchStatus;
use App\Models\PunchType;
use App\Models\SpecialLeaveUsage;
use App\Models\WorkStyle;
use App\Support\LocalDateTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * UC-A001〜A004・UC-A012〜UC-A014: 有効な打刻ログ(`status=active`)を集めて、
 * `AttendancePunchReconciler::classify()`の判定結果に応じて attendance_days /
 * attendance_breaks に反映する。Web画面の出退勤操作(`WebPunchDispatcher`経由)・共有端末・
 * 個人端末のいずれからの打刻も、記録・訂正・削除の後に必ずこの1つの規則を通る
 * (経路ごとに計算ロジックを複製しない。docs/03-architecture.md 3.5)。
 *
 * - Complete(出勤〜退勤まで矛盾なく組み立てられる): 実績を確定し、日次計算も行う。
 * - InProgress(まだ退勤していないが、ここまでの打刻に矛盾はない): 最新の打刻から
 *   `attendance_days.status`だけを反映する(社員本人・管理者が「今の状態」を見て取れる
 *   ようにするため)。
 * - Contradictory(出勤・退勤の重複や順序の矛盾など): `attendance_days`は更新せず、
 *   ログに警告を残すだけに留める。打刻ログ自体は矛盾があっても常に記録済みであり
 *   (UC-A012)、矛盾の解消(実績の最終判断)はUC-A005の日次編集でユーザー自身が行う。
 *
 * 既に退勤済みの日は、以降の打刻では状態を変えない(矛盾の解消はUC-A005の日次編集で行う)。
 *
 * 打刻(attendance_punch集約)と日次勤怠(attendance_day集約)は別の集約ストリームだが、
 * 1回の打刻操作で両方に書き込みが生じるケースがあるため、このサービスはイベントを
 * 記録するだけで永続化(persist)しない。呼び出し元のHandlerが打刻集約と合わせて
 * `AggregateRoot::persistInTransaction()`で1トランザクションにまとめて永続化する
 * (docs/29-event-sourcing-framework-migration.md参照)。そのため、まだ永続化されていない
 * (DBに存在しない)打刻についても`$activePunchesOverride`で有効な打刻集合を呼び出し元から
 * 渡してもらう形にしている(prepareの時点でDBを再クエリしても、当該打刻はまだ書き込まれて
 * いないため見えない)。
 */
class AttendanceDayPunchSyncer
{
    /** 標準休憩の自動補完を検討する最短実働時間(6時間)。 */
    private const AUTO_BREAK_MINIMUM_WORK_MINUTES = 360;

    public function __construct(
        private readonly AttendancePunchReconciler $reconciler,
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceEditGuard $guard,
        private readonly WorkStyleFallbackResolver $workStyleFallbackResolver,
    ) {}

    /**
     * @param  Collection<int, AttendancePunch>|null  $activePunchesOverride  未永続化の
     *                                                                        打刻の変更(記録・訂正・削除)を反映した、有効な打刻の一覧。nullの場合はDBから直接取得する
     *                                                                        (打刻自体の変更を伴わない再同期、例: DeleteAttendanceDayHandlerのRECREATE_FROM_PUNCHESで使う)。
     * @return AttendanceDayAggregate|null 記録すべきイベントが無い場合はnull(呼び出し元は
     *                                     persistInTransactionに含めなくてよい)。
     */
    public function prepare(string $userId, string $workDate, ?Collection $activePunchesOverride = null): ?AttendanceDayAggregate
    {
        $punches = $activePunchesOverride ?? AttendancePunch::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', $workDate)
            ->where('status', PunchStatus::ACTIVE)
            ->orderBy('punched_at')
            ->with('device')
            ->get();

        if ($punches->isEmpty()) {
            return null;
        }

        $day = AttendanceDay::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', $workDate)
            ->first();

        if ($day !== null && $day->source !== AttendanceDaySource::PUNCH) {
            // 画面からの操作・日次編集で既に確定した日は、打刻ログで上書きしない。
            return null;
        }

        if ($day !== null && $day->status === AttendanceDayStatus::CLOCKED_OUT) {
            // 既に退勤済みの日は、以降の打刻ログでは状態を変えない。
            return null;
        }

        if (! $this->guard->isMutable($day, $userId, $workDate)) {
            // 締め後にロック済み、または承認済み・締め済みの月に属する日は、
            // 打刻の記録・訂正・削除では上書きしない(修正申請ワークフローを使う)。
            return null;
        }

        $result = $this->reconciler->classify($punches);

        if ($result->outcome === PunchLogOutcome::Contradictory) {
            // 打刻ログ自体は既に記録済み(UC-A012)。矛盾がある間はattendance_daysを
            // 更新せず、ログに警告を残すだけに留める(最終判断はUC-A005の日次編集で
            // ユーザー自身に委ねる)。
            Log::warning('打刻ログに矛盾があるため、日次実績への反映をスキップしました。', [
                'user_id' => $userId,
                'work_date' => $workDate,
                'reason' => $result->reason,
            ]);

            return null;
        }

        $dayId = $day->id ?? (string) Str::uuid();
        $aggregate = AttendanceDayAggregate::retrieve($dayId);

        if ($result->outcome === PunchLogOutcome::InProgress) {
            return $this->syncLiveStatus($aggregate, $day, $userId, $workDate, $punches);
        }

        return $this->syncFromPunches($aggregate, $day, $userId, $workDate, $result->reconciled, $punches);
    }

    /**
     * @param  Collection<int, AttendancePunch>  $punches  同一user_id・work_dateのpunched_at昇順の打刻一覧
     */
    private function syncLiveStatus(
        AttendanceDayAggregate $aggregate,
        ?AttendanceDay $day,
        string $userId,
        string $workDate,
        Collection $punches,
    ): ?AttendanceDayAggregate {
        $latestPunch = $punches->last();
        if ($latestPunch === null) {
            return null;
        }

        $status = match ($latestPunch->punch_type) {
            PunchType::CLOCK_IN, PunchType::BREAK_END => AttendanceDayStatus::WORKING,
            PunchType::BREAK_START => AttendanceDayStatus::ON_BREAK,
            default => null,
        };

        if ($status === null) {
            return null;
        }

        if ($day !== null && $day->status === $status) {
            return null;
        }

        $shiftAssignmentId = $day?->shift_assignment_id ?? $this->resolveShiftAssignmentId($userId, $workDate);

        $actualStartAt = null;
        $utcOffsetMinutes = null;
        if ($status === AttendanceDayStatus::WORKING && $day?->actual_start_at === null) {
            $firstClockIn = $punches->firstWhere('punch_type', PunchType::CLOCK_IN);
            if ($firstClockIn !== null) {
                $actualStartAt = LocalDateTime::formatWithOffsetMinutes($firstClockIn->punched_at, $firstClockIn->utc_offset_minutes);
                $utcOffsetMinutes = $firstClockIn->utc_offset_minutes;
            }
        }

        $aggregate->syncLiveStatus(
            userId: $userId,
            workDate: $workDate,
            shiftAssignmentId: $shiftAssignmentId,
            status: $status,
            source: AttendanceDaySource::PUNCH,
            actualStartAt: $actualStartAt,
            utcOffsetMinutes: $utcOffsetMinutes,
        );

        return $aggregate;
    }

    /**
     * @param  array{clock_in: Carbon, clock_out: Carbon, breaks: array<int, array{start: Carbon, end: Carbon}>, utc_offset_minutes: int}  $reconciled
     * @param  Collection<int, AttendancePunch>  $punches
     */
    private function syncFromPunches(
        AttendanceDayAggregate $aggregate,
        ?AttendanceDay $day,
        string $userId,
        string $workDate,
        array $reconciled,
        Collection $punches,
    ): AttendanceDayAggregate {
        $shiftAssignmentId = $day?->shift_assignment_id ?? $this->resolveShiftAssignmentId($userId, $workDate);
        $offsetMinutes = $reconciled['utc_offset_minutes'];

        // 打刻に使われた端末に既定の勤務形態区分が設定されていれば反映する
        // (docs/07-usecases-attendance.md「勤務形態区分」)。どの端末で打刻したか分からない
        // 場合は既存の値を保持する(勝手にクリアしない)。
        $workLocationType = $punches
            ->whereNotNull('device_id')
            ->reverse()
            ->map(fn ($punch) => $punch->device?->default_work_location_type)
            ->first(fn (?string $value) => $value !== null) ?? $day?->work_location_type;

        $breaksPayload = collect($reconciled['breaks'])
            ->map(fn (array $break) => [
                'start' => LocalDateTime::formatWithOffsetMinutes($break['start'], $offsetMinutes),
                'end' => LocalDateTime::formatWithOffsetMinutes($break['end'], $offsetMinutes),
            ])
            ->all();

        $aggregate->syncFromPunches(
            userId: $userId,
            workDate: $workDate,
            shiftAssignmentId: $shiftAssignmentId,
            actualStartAt: LocalDateTime::formatWithOffsetMinutes($reconciled['clock_in'], $offsetMinutes),
            actualEndAt: LocalDateTime::formatWithOffsetMinutes($reconciled['clock_out'], $offsetMinutes),
            utcOffsetMinutes: $offsetMinutes,
            workLocationType: $workLocationType,
            breaks: $breaksPayload,
        );

        $transientDay = $this->buildTransientDay(
            $aggregate,
            $userId,
            $workDate,
            $shiftAssignmentId,
            $reconciled,
            $workLocationType,
            $day,
        );

        $this->autoInsertStandardBreakIfApplicable($aggregate, $transientDay);

        $calculation = $this->calculator->calculate($transientDay);
        $aggregate->calculate($calculation);

        return $aggregate;
    }

    /**
     * AttendanceCalculatorはEloquentモデルの属性・リレーションのみを読むため、DBへの保存前でも
     * 計算できる。この日がまだ存在しない(初回の打刻)場合でも矛盾なく計算できるよう、
     * 未保存のAttendanceDayを組み立てて渡す。
     *
     * @param  array{clock_in: Carbon, clock_out: Carbon, breaks: array<int, array{start: Carbon, end: Carbon}>, utc_offset_minutes: int}  $reconciled
     */
    private function buildTransientDay(
        AttendanceDayAggregate $aggregate,
        string $userId,
        string $workDate,
        ?string $shiftAssignmentId,
        array $reconciled,
        ?string $workLocationType,
        ?AttendanceDay $existingDay,
    ): AttendanceDay {
        $day = new AttendanceDay([
            'id' => $aggregate->uuid(),
            'user_id' => $userId,
            'work_date' => $workDate,
            'shift_assignment_id' => $shiftAssignmentId,
            'status' => AttendanceDayStatus::CLOCKED_OUT,
            'source' => AttendanceDaySource::PUNCH,
            'utc_offset_minutes' => $reconciled['utc_offset_minutes'],
            'actual_start_at' => $reconciled['clock_in'],
            'actual_end_at' => $reconciled['clock_out'],
            'work_type' => $existingDay?->work_type,
            'work_location_type' => $workLocationType,
            'note' => $existingDay?->note,
        ]);

        $day->setRelation('breaks', collect($reconciled['breaks'])->map(
            fn (array $break) => new AttendanceBreak(['break_start_at' => $break['start'], 'break_end_at' => $break['end']]),
        )->values());

        $day->setRelation(
            'leaveSegments',
            $existingDay !== null
                ? AttendanceLeaveSegment::query()->where('attendance_day_id', $existingDay->id)->get()
                : collect(),
        );
        $day->setRelation(
            'paidLeaveUsages',
            $existingDay !== null
                ? PaidLeaveUsage::query()->where('attendance_day_id', $existingDay->id)->get()
                : collect(),
        );
        $day->setRelation(
            'specialLeaveUsages',
            $existingDay !== null
                ? SpecialLeaveUsage::query()->where('attendance_day_id', $existingDay->id)->get()
                : collect(),
        );

        $shiftAssignment = $shiftAssignmentId !== null
            ? EmployeeShiftAssignment::query()->with('workStyle.calendar')->find($shiftAssignmentId)
            : null;
        $day->setRelation('shiftAssignment', $shiftAssignment);

        return $day;
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
    private function autoInsertStandardBreakIfApplicable(AttendanceDayAggregate $aggregate, AttendanceDay $transientDay): void
    {
        if ($transientDay->breaks->isNotEmpty()) {
            return;
        }

        $start = $transientDay->actual_start_at;
        $end = $transientDay->actual_end_at;
        if ($start === null || $end === null) {
            return;
        }

        if ($start->diffInMinutes($end) < self::AUTO_BREAK_MINIMUM_WORK_MINUTES) {
            return;
        }

        $workStyle = $transientDay->shiftAssignment?->workStyle
            ?? $this->workStyleFallbackResolver->resolveForUser($transientDay->user_id, $transientDay->work_date->copy());

        if (! $this->supportsAutoBreak($workStyle)) {
            return;
        }

        $breakStart = $transientDay->work_date->copy()->setTimeFromTimeString($workStyle->default_break_start_time);
        $breakEnd = $transientDay->work_date->copy()->setTimeFromTimeString($workStyle->default_break_end_time);

        if ($breakEnd->lessThanOrEqualTo($breakStart)) {
            return;
        }

        if (! ($start->lessThanOrEqualTo($breakStart) && $breakEnd->lessThanOrEqualTo($end))) {
            return;
        }

        $aggregate->autoInsertBreak(
            workStyleId: $workStyle->id,
            breakStartAt: LocalDateTime::formatWithOffsetMinutes($breakStart, $transientDay->utc_offset_minutes),
            breakEndAt: LocalDateTime::formatWithOffsetMinutes($breakEnd, $transientDay->utc_offset_minutes),
        );

        // 計算(AttendanceCalculator)にもこの自動補完した休憩を反映する。
        $transientDay->setRelation(
            'breaks',
            $transientDay->breaks->push(new AttendanceBreak(['break_start_at' => $breakStart, 'break_end_at' => $breakEnd])),
        );
    }

    private function supportsAutoBreak(?WorkStyle $workStyle): bool
    {
        return $workStyle !== null
            && $workStyle->auto_break_enabled
            && $workStyle->default_break_start_time !== null
            && $workStyle->default_break_end_time !== null;
    }

    private function resolveShiftAssignmentId(string $userId, string $workDate): ?string
    {
        return EmployeeShiftAssignment::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', $workDate)
            ->value('id');
    }
}
