<?php

namespace App\Domain\Leave\Projectors;

use App\Domain\Attendance\Events\AttendanceDayCorrected;
use App\Domain\Attendance\Events\AttendanceDayCreated;
use App\Domain\Attendance\Events\AttendanceDayDeleted;
use App\Domain\Attendance\Events\AttendanceDayEdited;
use App\Domain\Attendance\Events\AttendanceDayLiveStatusSynced;
use App\Domain\Attendance\Events\AttendanceDaySyncedFromPunches;
use App\Domain\Attendance\Events\EmployeeCalendarEntryAssigned;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestApproved;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestCancelled;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestReturned;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestResubmitted;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestShared;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequested;
use App\Domain\PaidLeave\Events\PaidLeaveRequestApproved;
use App\Domain\PaidLeave\Events\PaidLeaveRequestCancelled;
use App\Domain\PaidLeave\Events\PaidLeaveRequestReturned;
use App\Domain\PaidLeave\Events\PaidLeaveRequestShared;
use App\Domain\PaidLeave\Events\PaidLeaveRequested;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageCancelled;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageConfirmed;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageDesignated;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleApproved;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleCancelled;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleMigrated;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleRequested;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleResubmitted;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleReturned;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleShared;
use App\Domain\Leave\Support\LeaveAttendanceRateJudgement as J;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequested;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestApproved;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestCancelled;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestResubmitted;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestReturned;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestShared;
use App\Domain\Workflow\Events\WorkflowRequestReturned;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * 出勤率の入力(leave_attendance_rate_*)の共通処理。文脈ごとの表・モデルは継承先が抽象メソッドで渡す
 * (有給: PaidLeaveSchedule、特別休暇: SpecialLeave。表だけが違い、規則は同じ)。Spatieの自動検出の対象は継承先。
 *
 * 有給の出勤率の入力(leave_attendance_rate_days・leave_attendance_rate_leaves・
 * leave_attendance_rate_attendance_days)を、勤怠・休暇申請・カレンダーのイベントから作る(仕様確定事項F)。
 * 有給の文脈が自分の表として持つ(他文脈の表は読まない)。
 *
 * - 分母: employee_calendar_entry.assigned のisWorkingDay(同じ利用者・日付は後に処理したもの)。
 * - 出勤: 勤怠日の status が clocked_out(synced_from_punches は退勤済み)。勤怠日IDから利用者・日付を保持する。
 * - 休暇: 休暇申請3種のイベント。申請中・承認済みを有効とし、差戻し・取消は行を残して状態を変える。
 *   有給は AttendanceDayLeaveProjector と同じ系統の規則(旧 paid_leave.* / cutover後の paid_leave_account.usage_*
 *   + workflow_request.returned / 本変更後の paid_leave_request.*。申請IDごとに排他、migrated で切り替え)。
 *
 * 行の存在を前提にしない(再生の順序に依存させない)。全て upsert/update で書くため再処理しても結果は同じ。
 * 日単位の判定結果(全休・部分休暇)は LeaveAttendanceRateJudgement が求め、ビューに保存する。
 */
abstract class AbstractLeaveAttendanceRateProjector extends Projector
{
    abstract protected function dayModel(): string;

    abstract protected function attendanceDayModel(): string;

    abstract protected function leaveModel(): string;

    protected function dayQuery(): Builder
    {
        $class = $this->dayModel();

        return $class::query();
    }

    protected function attendanceDayQuery(): Builder
    {
        $class = $this->attendanceDayModel();

        return $class::query();
    }

    protected function leaveQuery(): Builder
    {
        $class = $this->leaveModel();

        return $class::query();
    }

    /**
     * 分母の行に書く属性(カレンダーの割当イベント由来)。継承先が追加の列を書くときに上書きする。
     *
     * @return array<string, mixed>
     */
    protected function calendarAttributes(EmployeeCalendarEntryAssigned $event): array
    {
        return ['is_working_day' => $event->isWorkingDay];
    }

    // ---- 出勤(勤怠のイベント) ----

    public function onEmployeeCalendarEntryAssigned(EmployeeCalendarEntryAssigned $event): void
    {
        $this->dayQuery()->updateOrCreate(
            ['user_id' => $event->userId, 'work_date' => $event->workDate],
            $this->calendarAttributes($event),
        );
    }

    public function onAttendanceDayCreated(AttendanceDayCreated $event): void
    {
        $this->recordAttendance($event->aggregateRootUuid(), $event->userId, $event->workDate, $event->status === J::STATUS_CLOCKED_OUT);
    }

    public function onAttendanceDayEdited(AttendanceDayEdited $event): void
    {
        $day = $this->attendanceDayQuery()->find($event->aggregateRootUuid());
        if ($day === null) {
            return;
        }

        $this->recordAttendance($day->id, $day->user_id, $day->work_date, $event->status === J::STATUS_CLOCKED_OUT);
    }

    public function onAttendanceDaySyncedFromPunches(AttendanceDaySyncedFromPunches $event): void
    {
        $this->recordAttendance($event->aggregateRootUuid(), $event->userId, $event->workDate, true);
    }

    /** 補正イベントは勤怠日の利用者・日付と出勤状態を今の値で置く(欠落していた行の補完を含む)。 */
    public function onAttendanceDayCorrected(AttendanceDayCorrected $event): void
    {
        $this->recordAttendance($event->aggregateRootUuid(), $event->userId, $event->workDate, $event->status === J::STATUS_CLOCKED_OUT);
    }

    public function onAttendanceDayLiveStatusSynced(AttendanceDayLiveStatusSynced $event): void
    {
        $this->recordAttendance($event->aggregateRootUuid(), $event->userId, $event->workDate, $event->status === J::STATUS_CLOCKED_OUT);
    }

    public function onAttendanceDayDeleted(AttendanceDayDeleted $event): void
    {
        $day = $this->attendanceDayQuery()->find($event->aggregateRootUuid());
        if ($day === null) {
            return;
        }

        $userId = $day->user_id;
        $workDate = $day->work_date;
        $day->delete();
        $this->refresh($userId, $workDate);
    }

    // ---- 有給・旧系統(旧paid_leave.*。再生用に残置されたイベント) ----

    public function onPaidLeaveRequested(PaidLeaveRequested $event): void
    {
        $this->createLeave(J::KIND_PAID, $event->aggregateRootUuid(), 'legacy_paid', [
            'user_id' => $event->userId,
            'work_date' => $event->targetDate,
            'unit' => $event->leaveType,
            'request_status' => J::STATUS_SUBMITTED,
        ]);
    }

    public function onPaidLeaveRequestApproved(PaidLeaveRequestApproved $event): void
    {
        $this->transitionLeave(J::KIND_PAID, $event->aggregateRootUuid(), 'legacy_paid', ['request_status' => J::STATUS_APPROVED]);
    }

    public function onPaidLeaveRequestReturned(PaidLeaveRequestReturned $event): void
    {
        $this->transitionLeave(J::KIND_PAID, $event->aggregateRootUuid(), 'legacy_paid', ['request_status' => J::STATUS_RETURNED]);
    }

    public function onPaidLeaveRequestCancelled(PaidLeaveRequestCancelled $event): void
    {
        $this->transitionLeave(J::KIND_PAID, $event->aggregateRootUuid(), 'legacy_paid', ['request_status' => J::STATUS_CANCELLED]);
    }

    public function onPaidLeaveRequestShared(PaidLeaveRequestShared $event): void
    {
        $this->transitionLeave(J::KIND_PAID, $event->aggregateRootUuid(), 'legacy_paid', ['workflow_request_id' => $event->workflowRequestId]);
    }

    // ---- 有給・cutover後の系統(paid_leave_account.usage_*) ----

    public function onPaidLeaveUsageDesignated(PaidLeaveUsageDesignated $event): void
    {
        // 有給の申請IDを持たない消化記録は出勤率の行にできない(キーが無い)。
        if ($event->paidLeaveRequestId === null) {
            return;
        }

        $this->createLeave(J::KIND_PAID, $event->paidLeaveRequestId, 'paid_account', [
            'user_id' => $event->aggregateRootUuid(),
            'work_date' => $event->usedOn,
            'unit' => $event->usageType,
            'usage_id' => $event->usageId,
            'workflow_request_id' => $event->workflowRequestId,
            'request_status' => J::STATUS_SUBMITTED,
        ]);
    }

    public function onPaidLeaveUsageConfirmed(PaidLeaveUsageConfirmed $event): void
    {
        $this->transitionLeaveByUsage($event->usageId, J::STATUS_APPROVED);
    }

    public function onPaidLeaveUsageCancelled(PaidLeaveUsageCancelled $event): void
    {
        $this->transitionLeaveByUsage($event->usageId, J::STATUS_CANCELLED);
    }

    /** cutover後の系統の行のうち、ワークフローIDが一致し申請中のものを差戻しにする。 */
    public function onWorkflowRequestReturned(WorkflowRequestReturned $event): void
    {
        $this->leaveQuery()
            ->where('leave_kind', J::KIND_PAID)
            ->where('source', 'paid_account')
            ->where('workflow_request_id', $event->aggregateRootUuid())
            ->where('request_status', J::STATUS_SUBMITTED)
            ->get()
            ->each(fn (Model $leave) => $this->updateLeave($leave, ['request_status' => J::STATUS_RETURNED]));
    }

    // ---- 有給・新系統(paid_leave_request.*) ----

    public function onPaidLeaveRequestLifecycleRequested(PaidLeaveRequestLifecycleRequested $event): void
    {
        $this->createLeave(J::KIND_PAID, $event->aggregateRootUuid(), 'paid_request', [
            'user_id' => $event->userId,
            'work_date' => $event->targetDate,
            'unit' => $event->leaveType,
            'workflow_request_id' => $event->workflowRequestId,
            'request_status' => J::STATUS_SUBMITTED,
        ], takeover: true);
    }

    public function onPaidLeaveRequestLifecycleResubmitted(PaidLeaveRequestLifecycleResubmitted $event): void
    {
        $this->transitionLeave(J::KIND_PAID, $event->aggregateRootUuid(), 'paid_request', ['request_status' => J::STATUS_SUBMITTED]);
    }

    public function onPaidLeaveRequestLifecycleApproved(PaidLeaveRequestLifecycleApproved $event): void
    {
        $this->transitionLeave(J::KIND_PAID, $event->aggregateRootUuid(), 'paid_request', ['request_status' => J::STATUS_APPROVED]);
    }

    public function onPaidLeaveRequestLifecycleReturned(PaidLeaveRequestLifecycleReturned $event): void
    {
        $this->transitionLeave(J::KIND_PAID, $event->aggregateRootUuid(), 'paid_request', ['request_status' => J::STATUS_RETURNED]);
    }

    public function onPaidLeaveRequestLifecycleCancelled(PaidLeaveRequestLifecycleCancelled $event): void
    {
        $this->transitionLeave(J::KIND_PAID, $event->aggregateRootUuid(), 'paid_request', ['request_status' => J::STATUS_CANCELLED]);
    }

    public function onPaidLeaveRequestLifecycleShared(PaidLeaveRequestLifecycleShared $event): void
    {
        $this->transitionLeave(J::KIND_PAID, $event->aggregateRootUuid(), 'paid_request', ['workflow_request_id' => $event->workflowRequestId]);
    }

    /** 本変更前の申請の引き継ぎ。以後この申請IDは新系統だけで状態を作る。 */
    public function onPaidLeaveRequestLifecycleMigrated(PaidLeaveRequestLifecycleMigrated $event): void
    {
        $this->createLeave(J::KIND_PAID, $event->aggregateRootUuid(), 'paid_request', [
            'user_id' => $event->userId,
            'work_date' => $event->targetDate,
            'unit' => $event->leaveType,
            'usage_id' => $event->usageId,
            'workflow_request_id' => $event->workflowRequestId,
            'request_status' => $event->status,
        ], takeover: true);
    }

    // ---- 特別休暇 ----

    public function onSpecialLeaveRequested(SpecialLeaveRequested $event): void
    {
        $this->createLeave(J::KIND_SPECIAL, $event->aggregateRootUuid(), 'special', [
            'user_id' => $event->userId,
            'work_date' => $event->targetDate,
            'unit' => $event->leaveType,
            'request_status' => J::STATUS_SUBMITTED,
        ]);
    }

    public function onSpecialLeaveRequestApproved(SpecialLeaveRequestApproved $event): void
    {
        $this->transitionLeave(J::KIND_SPECIAL, $event->aggregateRootUuid(), 'special', ['request_status' => J::STATUS_APPROVED]);
    }

    public function onSpecialLeaveRequestReturned(SpecialLeaveRequestReturned $event): void
    {
        $this->transitionLeave(J::KIND_SPECIAL, $event->aggregateRootUuid(), 'special', ['request_status' => J::STATUS_RETURNED]);
    }

    /** 差戻し中の特別休暇の再提出: 差戻しの行だけを申請中に戻す。 */
    public function onSpecialLeaveRequestResubmitted(SpecialLeaveRequestResubmitted $event): void
    {
        $this->transitionLeave(J::KIND_SPECIAL, $event->aggregateRootUuid(), 'special', ['request_status' => J::STATUS_SUBMITTED], onlyFrom: J::STATUS_RETURNED);
    }

    public function onSpecialLeaveRequestCancelled(SpecialLeaveRequestCancelled $event): void
    {
        $this->transitionLeave(J::KIND_SPECIAL, $event->aggregateRootUuid(), 'special', ['request_status' => J::STATUS_CANCELLED]);
    }

    public function onSpecialLeaveRequestShared(SpecialLeaveRequestShared $event): void
    {
        $this->transitionLeave(J::KIND_SPECIAL, $event->aggregateRootUuid(), 'special', ['workflow_request_id' => $event->workflowRequestId]);
    }

    // ---- 代休 ----

    public function onCompensatoryLeaveRequested(CompensatoryLeaveRequested $event): void
    {
        $this->createLeave(J::KIND_COMPENSATORY, $event->aggregateRootUuid(), 'compensatory', [
            'user_id' => $event->userId,
            'work_date' => $event->targetDate,
            'unit' => $event->leaveType,
            'request_status' => J::STATUS_SUBMITTED,
        ]);
    }

    public function onCompensatoryLeaveRequestApproved(CompensatoryLeaveRequestApproved $event): void
    {
        $this->transitionLeave(J::KIND_COMPENSATORY, $event->aggregateRootUuid(), 'compensatory', ['request_status' => J::STATUS_APPROVED]);
    }

    public function onCompensatoryLeaveRequestReturned(CompensatoryLeaveRequestReturned $event): void
    {
        $this->transitionLeave(J::KIND_COMPENSATORY, $event->aggregateRootUuid(), 'compensatory', ['request_status' => J::STATUS_RETURNED]);
    }

    /** 差戻し中の代休の再提出: 差戻しの行だけを申請中に戻す。 */
    public function onCompensatoryLeaveRequestResubmitted(CompensatoryLeaveRequestResubmitted $event): void
    {
        $this->transitionLeave(J::KIND_COMPENSATORY, $event->aggregateRootUuid(), 'compensatory', ['request_status' => J::STATUS_SUBMITTED], onlyFrom: J::STATUS_RETURNED);
    }

    public function onCompensatoryLeaveRequestCancelled(CompensatoryLeaveRequestCancelled $event): void
    {
        $this->transitionLeave(J::KIND_COMPENSATORY, $event->aggregateRootUuid(), 'compensatory', ['request_status' => J::STATUS_CANCELLED]);
    }

    public function onCompensatoryLeaveRequestShared(CompensatoryLeaveRequestShared $event): void
    {
        $this->transitionLeave(J::KIND_COMPENSATORY, $event->aggregateRootUuid(), 'compensatory', ['workflow_request_id' => $event->workflowRequestId]);
    }

    // ---- 内部 ----

    /**
     * 勤怠日の出勤状態を記録する。勤怠日IDの利用者・日付が変わる場合は、変わる前の日も再計算する。
     */
    private function recordAttendance(string $attendanceDayId, string $userId, string $workDate, bool $clockedOut): void
    {
        $previous = $this->attendanceDayQuery()->find($attendanceDayId);

        $this->attendanceDayQuery()->updateOrCreate(
            ['id' => $attendanceDayId],
            ['user_id' => $userId, 'work_date' => $workDate, 'clocked_out' => $clockedOut],
        );

        if ($previous !== null && ($previous->user_id !== $userId || $previous->work_date !== $workDate)) {
            $this->refresh($previous->user_id, $previous->work_date);
        }
        $this->refresh($userId, $workDate);
    }

    /**
     * 休暇の行を作る(既存行があれば全項目を上書きする)。takeover=falseのとき、既存行が別の系統なら何もしない。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createLeave(string $leaveKind, ?string $leaveRequestId, string $source, array $attributes, bool $takeover = false): void
    {
        // 申請IDが無いイベントは行を作れないため無視する(例外にしない)。
        if ($leaveRequestId === null) {
            return;
        }

        $existing = $this->findLeave($leaveKind, $leaveRequestId);
        if (! $takeover && $existing !== null && $existing->source !== $source) {
            return;
        }

        $leave = $this->leaveQuery()->updateOrCreate(
            ['leave_kind' => $leaveKind, 'leave_request_id' => $leaveRequestId],
            $attributes + ['source' => $source],
        );

        if ($existing !== null && ($existing->user_id !== $leave->user_id || $existing->work_date !== $leave->work_date)) {
            $this->refresh($existing->user_id, $existing->work_date);
        }
        $this->refresh($leave->user_id, $leave->work_date);
    }

    /**
     * 既存行が同じ系統のときだけ状態を更新する。行が無い・別の系統なら何もしない。
     * onlyFrom を指定したときは、その状態の行だけを更新する(再提出は差戻しの行だけ)。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function transitionLeave(string $leaveKind, ?string $leaveRequestId, string $source, array $attributes, ?string $onlyFrom = null): void
    {
        if ($leaveRequestId === null) {
            return;
        }

        $leave = $this->findLeave($leaveKind, $leaveRequestId);
        if ($leave === null || $leave->source !== $source) {
            return;
        }
        if ($onlyFrom !== null && $leave->request_status !== $onlyFrom) {
            return;
        }

        $this->updateLeave($leave, $attributes);
    }

    /** 有給の消化記録ID→休暇の行(cutover後の系統)の状態を更新する。 */
    private function transitionLeaveByUsage(string $usageId, string $requestStatus): void
    {
        $leave = $this->leaveQuery()
            ->where('leave_kind', J::KIND_PAID)
            ->where('source', 'paid_account')
            ->where('usage_id', $usageId)
            ->first();
        if ($leave === null) {
            return;
        }

        $this->updateLeave($leave, ['request_status' => $requestStatus]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function updateLeave(Model $leave, array $attributes): void
    {
        $leave->update($attributes);
        $this->refresh($leave->user_id, $leave->work_date);
    }

    private function findLeave(string $leaveKind, ?string $leaveRequestId): ?Model
    {
        if ($leaveRequestId === null) {
            return null;
        }

        return $this->leaveQuery()
            ->where('leave_kind', $leaveKind)
            ->where('leave_request_id', $leaveRequestId)
            ->first();
    }

    /**
     * 利用者・日付の出勤率ビューを、勤怠日の出勤状態と有効な休暇から作り直す。分母(is_working_day)は変えない。
     */
    private function refresh(string $userId, string $workDate): void
    {
        $activeLeaves = $this->leaveQuery()
            ->where('user_id', $userId)
            ->whereDate('work_date', $workDate)
            ->whereIn('request_status', J::activeStatuses())
            ->get(['leave_kind', 'unit'])
            ->map(fn (Model $leave) => ['kind' => $leave->leave_kind, 'unit' => $leave->unit])
            ->values()
            ->all();

        $attended = $this->attendanceDayQuery()
            ->where('user_id', $userId)
            ->whereDate('work_date', $workDate)
            ->where('clocked_out', true)
            ->exists();

        $coverage = J::coverage($activeLeaves);

        $this->dayQuery()->updateOrCreate(
            ['user_id' => $userId, 'work_date' => $workDate],
            [
                'attended' => $attended,
                'full_leave_kinds' => $coverage['full'],
                'partial_leave_kinds' => $coverage['partial'],
            ],
        );
    }
}
