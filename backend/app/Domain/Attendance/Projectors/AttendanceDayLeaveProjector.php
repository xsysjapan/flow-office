<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestApproved;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestCancelled;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequested;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestResubmitted;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestReturned;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestShared;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleApproved;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleCancelled;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleRequested;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleResubmitted;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleReturned;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleShared;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestApproved;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestCancelled;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequested;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestResubmitted;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestReturned;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestShared;
use App\Models\AttendanceDayLeave;
use App\Models\PaidLeaveType;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * 休暇申請文脈のイベントから attendance_day_leaves(休暇ビュー)を作る
 * (.claude/skills/add-projection 参照)。行のキーは(leave_kind, leave_request_id)。
 *
 * 入力は休暇申請のイベント(有給は`paid_leave_request.*`、特別休暇は`special_leave.*`、代休は`compensatory_leave.*`)
 * だけで、口座・ワークフローのイベントは読まない。差戻し・取消でも行は削除せず request_status を変える。
 * 有効な休暇の判定は AttendanceDayLeaves が行う。全て upsert/update で書くため再処理しても結果は同じ。
 */
class AttendanceDayLeaveProjector extends Projector
{
    // ---- 有給(paid_leave_request.*) ----

    public function onPaidLeaveRequestLifecycleRequested(PaidLeaveRequestLifecycleRequested $event): void
    {
        $this->create(
            AttendanceDayLeave::KIND_PAID,
            $event->aggregateRootUuid(),
            [
                'user_id' => $event->userId,
                'work_date' => $event->targetDate,
                'unit' => $event->leaveType,
                'hours' => $event->hours,
                'minutes' => $this->minutesOf($event->leaveType, $event->hours),
                'special_leave_type_id' => null,
                'workflow_request_id' => $event->workflowRequestId,
                'request_status' => AttendanceDayLeave::STATUS_SUBMITTED,
            ],
        );
    }

    public function onPaidLeaveRequestLifecycleResubmitted(PaidLeaveRequestLifecycleResubmitted $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_PAID, $event->aggregateRootUuid(), [
            'request_status' => AttendanceDayLeave::STATUS_SUBMITTED,
        ]);
    }

    public function onPaidLeaveRequestLifecycleApproved(PaidLeaveRequestLifecycleApproved $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_PAID, $event->aggregateRootUuid(), [
            'request_status' => AttendanceDayLeave::STATUS_APPROVED,
        ]);
    }

    public function onPaidLeaveRequestLifecycleReturned(PaidLeaveRequestLifecycleReturned $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_PAID, $event->aggregateRootUuid(), [
            'request_status' => AttendanceDayLeave::STATUS_RETURNED,
        ]);
    }

    public function onPaidLeaveRequestLifecycleCancelled(PaidLeaveRequestLifecycleCancelled $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_PAID, $event->aggregateRootUuid(), [
            'request_status' => AttendanceDayLeave::STATUS_CANCELLED,
        ]);
    }

    public function onPaidLeaveRequestLifecycleShared(PaidLeaveRequestLifecycleShared $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_PAID, $event->aggregateRootUuid(), [
            'workflow_request_id' => $event->workflowRequestId,
        ]);
    }

    // ---- 特別休暇 ----

    public function onSpecialLeaveRequested(SpecialLeaveRequested $event): void
    {
        $this->create(
            AttendanceDayLeave::KIND_SPECIAL,
            $event->aggregateRootUuid(),
            [
                'user_id' => $event->userId,
                'work_date' => $event->targetDate,
                'unit' => $event->leaveType,
                'hours' => $event->hours,
                'minutes' => $this->minutesOf($event->leaveType, $event->hours),
                'special_leave_type_id' => $event->specialLeaveTypeId,
                'workflow_request_id' => null,
                'request_status' => AttendanceDayLeave::STATUS_SUBMITTED,
            ],
        );
    }

    public function onSpecialLeaveRequestApproved(SpecialLeaveRequestApproved $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_SPECIAL, $event->aggregateRootUuid(), [
            'request_status' => AttendanceDayLeave::STATUS_APPROVED,
        ]);
    }

    public function onSpecialLeaveRequestReturned(SpecialLeaveRequestReturned $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_SPECIAL, $event->aggregateRootUuid(), [
            'request_status' => AttendanceDayLeave::STATUS_RETURNED,
        ]);
    }

    /** 差戻し中の特別休暇の再提出: 差戻しの行だけを申請中に戻す(申請中・承認済み・取消の行は変えない)。 */
    public function onSpecialLeaveRequestResubmitted(SpecialLeaveRequestResubmitted $event): void
    {
        $this->transitionFrom(AttendanceDayLeave::KIND_SPECIAL, $event->aggregateRootUuid(), AttendanceDayLeave::STATUS_RETURNED, [
            'request_status' => AttendanceDayLeave::STATUS_SUBMITTED,
        ]);
    }

    public function onSpecialLeaveRequestCancelled(SpecialLeaveRequestCancelled $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_SPECIAL, $event->aggregateRootUuid(), [
            'request_status' => AttendanceDayLeave::STATUS_CANCELLED,
        ]);
    }

    public function onSpecialLeaveRequestShared(SpecialLeaveRequestShared $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_SPECIAL, $event->aggregateRootUuid(), [
            'workflow_request_id' => $event->workflowRequestId,
        ]);
    }

    // ---- 代休 ----

    public function onCompensatoryLeaveRequested(CompensatoryLeaveRequested $event): void
    {
        $this->create(
            AttendanceDayLeave::KIND_COMPENSATORY,
            $event->aggregateRootUuid(),
            [
                'user_id' => $event->userId,
                'work_date' => $event->targetDate,
                'unit' => $event->leaveType,
                'hours' => $event->hours,
                // 代休は申請時の分数(requestedMinutes)を優先する。
                'minutes' => $this->minutesOf($event->leaveType, $event->hours, $event->requestedMinutes),
                'special_leave_type_id' => null,
                'workflow_request_id' => null,
                'request_status' => AttendanceDayLeave::STATUS_SUBMITTED,
            ],
        );
    }

    public function onCompensatoryLeaveRequestApproved(CompensatoryLeaveRequestApproved $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_COMPENSATORY, $event->aggregateRootUuid(), [
            'request_status' => AttendanceDayLeave::STATUS_APPROVED,
        ]);
    }

    public function onCompensatoryLeaveRequestReturned(CompensatoryLeaveRequestReturned $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_COMPENSATORY, $event->aggregateRootUuid(), [
            'request_status' => AttendanceDayLeave::STATUS_RETURNED,
        ]);
    }

    /** 差戻し中の代休の再提出: 差戻しの行だけを申請中に戻す(申請中・承認済み・取消の行は変えない)。 */
    public function onCompensatoryLeaveRequestResubmitted(CompensatoryLeaveRequestResubmitted $event): void
    {
        $this->transitionFrom(AttendanceDayLeave::KIND_COMPENSATORY, $event->aggregateRootUuid(), AttendanceDayLeave::STATUS_RETURNED, [
            'request_status' => AttendanceDayLeave::STATUS_SUBMITTED,
        ]);
    }

    public function onCompensatoryLeaveRequestCancelled(CompensatoryLeaveRequestCancelled $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_COMPENSATORY, $event->aggregateRootUuid(), [
            'request_status' => AttendanceDayLeave::STATUS_CANCELLED,
        ]);
    }

    public function onCompensatoryLeaveRequestShared(CompensatoryLeaveRequestShared $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_COMPENSATORY, $event->aggregateRootUuid(), [
            'workflow_request_id' => $event->workflowRequestId,
        ]);
    }

    /**
     * 行を作る(既存行があれば全項目を上書きする)。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function create(string $leaveKind, ?string $leaveRequestId, array $attributes): void
    {
        // 申請IDが無いイベントは行を作れないため無視する(例外にしない)。
        if ($leaveRequestId === null) {
            return;
        }

        AttendanceDayLeave::query()->updateOrCreate(
            ['leave_kind' => $leaveKind, 'leave_request_id' => $leaveRequestId],
            $attributes,
        );
    }

    /**
     * 既存行の状態を更新する。行が無ければ何もしない(再生の順序に依存させない)。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function transition(string $leaveKind, string $leaveRequestId, array $attributes): void
    {
        $this->find($leaveKind, $leaveRequestId)?->update($attributes);
    }

    /**
     * 既存行の現在の状態が$fromStatusのときだけ更新する(差戻しからの再提出など)。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function transitionFrom(string $leaveKind, string $leaveRequestId, string $fromStatus, array $attributes): void
    {
        $leave = $this->find($leaveKind, $leaveRequestId);
        if ($leave === null || $leave->request_status !== $fromStatus) {
            return;
        }

        $leave->update($attributes);
    }

    private function find(string $leaveKind, string $leaveRequestId): ?AttendanceDayLeave
    {
        return AttendanceDayLeave::query()
            ->where('leave_kind', $leaveKind)
            ->where('leave_request_id', $leaveRequestId)
            ->first();
    }

    /**
     * 時間休の分数。時間休以外はnull。代休の申請時分数(requestedMinutes)があればそれを優先し、
     * 無ければ round(hours*60)(現行の消化記録の算出と同じ)。
     */
    private function minutesOf(string $unit, ?float $hours, ?int $requestedMinutes = null): ?int
    {
        if ($unit !== PaidLeaveType::HOURLY) {
            return null;
        }

        if ($requestedMinutes !== null) {
            return $requestedMinutes;
        }

        return $hours === null ? null : (int) round($hours * 60);
    }
}
