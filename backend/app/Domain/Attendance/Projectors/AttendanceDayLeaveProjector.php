<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestApproved;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestCancelled;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestReturned;
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
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestApproved;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestCancelled;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestReturned;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestShared;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequested;
use App\Domain\Workflow\Events\WorkflowRequestReturned;
use App\Models\AttendanceDayLeave;
use App\Models\AttendanceDayLeavePaidUsage;
use App\Models\PaidLeaveType;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * 休暇申請文脈のイベントから attendance_day_leaves(休暇ビュー)を作る
 * (.claude/skills/add-projection 参照)。行のキーは(leave_kind, leave_request_id)。
 *
 * 系統(source)の規則(論点4・論点13・仕様確定事項I):
 * - 有給は申請IDごとに1つの系統だけで行を作る。旧系統(legacy_paid: 旧paid_leave.*)、
 *   cutover後の系統(paid_account: paid_leave_account.usage_*+workflow_request.returned)、
 *   新系統(paid_request: paid_leave_request.*)。
 * - 新系統の作成イベント(Requested/Migrated)は既存行の系統を paid_request に切り替える。
 *   以後、旧系統・cutover後の系統のイベントは、その申請IDの行に適用しない。
 * - 作成イベントは、既存行が同じ系統(または無い)ときだけ適用する(排他)。
 * - 状態のイベントは、既存行があり同じ系統のときだけ適用する。行が無ければ何もしない
 *   (再生の順序に依存させない)。
 *
 * 差戻し・取消でも行は削除しない(request_statusを変える)。有効な休暇の判定は
 * AttendanceDayLeaves が行う。全て upsert/update で書くため再処理しても結果は同じ。
 */
class AttendanceDayLeaveProjector extends Projector
{
    // ---- 有給・旧系統(旧paid_leave.*。再生用に残置されたイベント) ----

    public function onPaidLeaveRequested(PaidLeaveRequested $event): void
    {
        $this->create(
            AttendanceDayLeave::KIND_PAID,
            $event->aggregateRootUuid(),
            AttendanceDayLeave::SOURCE_LEGACY_PAID,
            [
                'user_id' => $event->userId,
                'work_date' => $event->targetDate,
                'unit' => $event->leaveType,
                'hours' => $event->hours,
                'minutes' => $this->minutesOf($event->leaveType, $event->hours),
                'special_leave_type_id' => null,
                'workflow_request_id' => null,
                'request_status' => AttendanceDayLeave::STATUS_SUBMITTED,
            ],
        );
    }

    public function onPaidLeaveRequestApproved(PaidLeaveRequestApproved $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_PAID, $event->aggregateRootUuid(), AttendanceDayLeave::SOURCE_LEGACY_PAID, [
            'request_status' => AttendanceDayLeave::STATUS_APPROVED,
        ]);
    }

    public function onPaidLeaveRequestReturned(PaidLeaveRequestReturned $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_PAID, $event->aggregateRootUuid(), AttendanceDayLeave::SOURCE_LEGACY_PAID, [
            'request_status' => AttendanceDayLeave::STATUS_RETURNED,
        ]);
    }

    public function onPaidLeaveRequestCancelled(PaidLeaveRequestCancelled $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_PAID, $event->aggregateRootUuid(), AttendanceDayLeave::SOURCE_LEGACY_PAID, [
            'request_status' => AttendanceDayLeave::STATUS_CANCELLED,
        ]);
    }

    public function onPaidLeaveRequestShared(PaidLeaveRequestShared $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_PAID, $event->aggregateRootUuid(), AttendanceDayLeave::SOURCE_LEGACY_PAID, [
            'workflow_request_id' => $event->workflowRequestId,
        ]);
    }

    // ---- 有給・cutover後の系統(paid_leave_account.usage_*) ----

    public function onPaidLeaveUsageDesignated(PaidLeaveUsageDesignated $event): void
    {
        // 有給の申請IDを持たない消化記録は休暇ビューの行にできない(キーが無い)。
        if ($event->paidLeaveRequestId === null) {
            return;
        }

        $applied = $this->create(
            AttendanceDayLeave::KIND_PAID,
            $event->paidLeaveRequestId,
            AttendanceDayLeave::SOURCE_PAID_ACCOUNT,
            [
                'user_id' => $event->aggregateRootUuid(),
                'work_date' => $event->usedOn,
                'unit' => $event->usageType,
                'hours' => $event->hours,
                'minutes' => $this->minutesOf($event->usageType, $event->hours),
                'special_leave_type_id' => null,
                'workflow_request_id' => $event->workflowRequestId,
                'request_status' => AttendanceDayLeave::STATUS_SUBMITTED,
            ],
        );

        if ($applied) {
            AttendanceDayLeavePaidUsage::query()->updateOrCreate(
                ['usage_id' => $event->usageId],
                ['leave_request_id' => $event->paidLeaveRequestId],
            );
        }
    }

    public function onPaidLeaveUsageConfirmed(PaidLeaveUsageConfirmed $event): void
    {
        $this->transitionByUsage($event->usageId, AttendanceDayLeave::STATUS_APPROVED);
    }

    public function onPaidLeaveUsageCancelled(PaidLeaveUsageCancelled $event): void
    {
        $this->transitionByUsage($event->usageId, AttendanceDayLeave::STATUS_CANCELLED);
    }

    /**
     * cutover後の系統の行のうち、ワークフローIDが一致し申請中(submitted)のものを差戻しにする。
     * 取消済み・承認済みの行は変えない。
     */
    public function onWorkflowRequestReturned(WorkflowRequestReturned $event): void
    {
        AttendanceDayLeave::query()
            ->where('source', AttendanceDayLeave::SOURCE_PAID_ACCOUNT)
            ->where('workflow_request_id', $event->aggregateRootUuid())
            ->where('request_status', AttendanceDayLeave::STATUS_SUBMITTED)
            ->get()
            ->each(fn (AttendanceDayLeave $leave) => $leave->update([
                'request_status' => AttendanceDayLeave::STATUS_RETURNED,
            ]));
    }

    // ---- 有給・新系統(paid_leave_request.*) ----

    public function onPaidLeaveRequestLifecycleRequested(PaidLeaveRequestLifecycleRequested $event): void
    {
        $this->create(
            AttendanceDayLeave::KIND_PAID,
            $event->aggregateRootUuid(),
            AttendanceDayLeave::SOURCE_PAID_REQUEST,
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
            takeover: true,
        );
    }

    public function onPaidLeaveRequestLifecycleResubmitted(PaidLeaveRequestLifecycleResubmitted $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_PAID, $event->aggregateRootUuid(), AttendanceDayLeave::SOURCE_PAID_REQUEST, [
            'request_status' => AttendanceDayLeave::STATUS_SUBMITTED,
        ]);
    }

    public function onPaidLeaveRequestLifecycleApproved(PaidLeaveRequestLifecycleApproved $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_PAID, $event->aggregateRootUuid(), AttendanceDayLeave::SOURCE_PAID_REQUEST, [
            'request_status' => AttendanceDayLeave::STATUS_APPROVED,
        ]);
    }

    public function onPaidLeaveRequestLifecycleReturned(PaidLeaveRequestLifecycleReturned $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_PAID, $event->aggregateRootUuid(), AttendanceDayLeave::SOURCE_PAID_REQUEST, [
            'request_status' => AttendanceDayLeave::STATUS_RETURNED,
        ]);
    }

    public function onPaidLeaveRequestLifecycleCancelled(PaidLeaveRequestLifecycleCancelled $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_PAID, $event->aggregateRootUuid(), AttendanceDayLeave::SOURCE_PAID_REQUEST, [
            'request_status' => AttendanceDayLeave::STATUS_CANCELLED,
        ]);
    }

    public function onPaidLeaveRequestLifecycleShared(PaidLeaveRequestLifecycleShared $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_PAID, $event->aggregateRootUuid(), AttendanceDayLeave::SOURCE_PAID_REQUEST, [
            'workflow_request_id' => $event->workflowRequestId,
        ]);
    }

    /** 本変更前の申請の引き継ぎ。以後この申請IDは新系統だけで状態を作る(旧系統は切り替えで無視される)。 */
    public function onPaidLeaveRequestLifecycleMigrated(PaidLeaveRequestLifecycleMigrated $event): void
    {
        $this->create(
            AttendanceDayLeave::KIND_PAID,
            $event->aggregateRootUuid(),
            AttendanceDayLeave::SOURCE_PAID_REQUEST,
            [
                'user_id' => $event->userId,
                'work_date' => $event->targetDate,
                'unit' => $event->leaveType,
                'hours' => $event->hours,
                'minutes' => $this->minutesOf($event->leaveType, $event->hours),
                'special_leave_type_id' => null,
                'workflow_request_id' => $event->workflowRequestId,
                'request_status' => $event->status,
            ],
            takeover: true,
        );
    }

    // ---- 特別休暇 ----

    public function onSpecialLeaveRequested(SpecialLeaveRequested $event): void
    {
        $this->create(
            AttendanceDayLeave::KIND_SPECIAL,
            $event->aggregateRootUuid(),
            AttendanceDayLeave::SOURCE_SPECIAL,
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
        $this->transition(AttendanceDayLeave::KIND_SPECIAL, $event->aggregateRootUuid(), AttendanceDayLeave::SOURCE_SPECIAL, [
            'request_status' => AttendanceDayLeave::STATUS_APPROVED,
        ]);
    }

    public function onSpecialLeaveRequestReturned(SpecialLeaveRequestReturned $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_SPECIAL, $event->aggregateRootUuid(), AttendanceDayLeave::SOURCE_SPECIAL, [
            'request_status' => AttendanceDayLeave::STATUS_RETURNED,
        ]);
    }

    public function onSpecialLeaveRequestCancelled(SpecialLeaveRequestCancelled $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_SPECIAL, $event->aggregateRootUuid(), AttendanceDayLeave::SOURCE_SPECIAL, [
            'request_status' => AttendanceDayLeave::STATUS_CANCELLED,
        ]);
    }

    public function onSpecialLeaveRequestShared(SpecialLeaveRequestShared $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_SPECIAL, $event->aggregateRootUuid(), AttendanceDayLeave::SOURCE_SPECIAL, [
            'workflow_request_id' => $event->workflowRequestId,
        ]);
    }

    // ---- 代休 ----

    public function onCompensatoryLeaveRequested(CompensatoryLeaveRequested $event): void
    {
        $this->create(
            AttendanceDayLeave::KIND_COMPENSATORY,
            $event->aggregateRootUuid(),
            AttendanceDayLeave::SOURCE_COMPENSATORY,
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
        $this->transition(AttendanceDayLeave::KIND_COMPENSATORY, $event->aggregateRootUuid(), AttendanceDayLeave::SOURCE_COMPENSATORY, [
            'request_status' => AttendanceDayLeave::STATUS_APPROVED,
        ]);
    }

    public function onCompensatoryLeaveRequestReturned(CompensatoryLeaveRequestReturned $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_COMPENSATORY, $event->aggregateRootUuid(), AttendanceDayLeave::SOURCE_COMPENSATORY, [
            'request_status' => AttendanceDayLeave::STATUS_RETURNED,
        ]);
    }

    public function onCompensatoryLeaveRequestCancelled(CompensatoryLeaveRequestCancelled $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_COMPENSATORY, $event->aggregateRootUuid(), AttendanceDayLeave::SOURCE_COMPENSATORY, [
            'request_status' => AttendanceDayLeave::STATUS_CANCELLED,
        ]);
    }

    public function onCompensatoryLeaveRequestShared(CompensatoryLeaveRequestShared $event): void
    {
        $this->transition(AttendanceDayLeave::KIND_COMPENSATORY, $event->aggregateRootUuid(), AttendanceDayLeave::SOURCE_COMPENSATORY, [
            'workflow_request_id' => $event->workflowRequestId,
        ]);
    }

    /**
     * 行を作る(既存行があれば全項目を上書きする)。takeover=falseのとき、既存行が別の系統なら何もしない。
     * 適用したら true。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function create(string $leaveKind, string $leaveRequestId, string $source, array $attributes, bool $takeover = false): bool
    {
        $existing = $this->find($leaveKind, $leaveRequestId);
        if (! $takeover && $existing !== null && $existing->source !== $source) {
            return false;
        }

        AttendanceDayLeave::query()->updateOrCreate(
            ['leave_kind' => $leaveKind, 'leave_request_id' => $leaveRequestId],
            $attributes + ['source' => $source],
        );

        return true;
    }

    /**
     * 既存行が同じ系統のときだけ状態を更新する。行が無い・別の系統なら何もしない。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function transition(string $leaveKind, string $leaveRequestId, string $source, array $attributes): void
    {
        $leave = $this->find($leaveKind, $leaveRequestId);
        if ($leave === null || $leave->source !== $source) {
            return;
        }

        $leave->update($attributes);
    }

    /** 有給の消化記録ID→申請IDの対応で、cutover後の系統の行の状態を更新する。 */
    private function transitionByUsage(string $usageId, string $requestStatus): void
    {
        $leaveRequestId = AttendanceDayLeavePaidUsage::query()->whereKey($usageId)->value('leave_request_id');
        if ($leaveRequestId === null) {
            return;
        }

        $this->transition(AttendanceDayLeave::KIND_PAID, $leaveRequestId, AttendanceDayLeave::SOURCE_PAID_ACCOUNT, [
            'request_status' => $requestStatus,
        ]);
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
