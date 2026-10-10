<?php

namespace App\Domain\PaidLeaveRequest\Projectors;

use App\Domain\PaidLeave\Events\PaidLeaveRequestApproved;
use App\Domain\PaidLeave\Events\PaidLeaveRequestCancelled;
use App\Domain\PaidLeave\Events\PaidLeaveRequestReturned;
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
use App\Domain\Workflow\Events\WorkflowRequestReturned;
use App\Models\LeaveRequestWorkflowLink;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use App\Models\PaidLeaveRequestUsageLink;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * 有給申請の申請状態を `paid_leave_requests` に作る(休暇申請文脈のProjector。
 * 論点4・論点13・仕様確定事項C。.claude/skills/add-projection 参照)。
 *
 * 行のキーは申請ID(有給の申請集約ID=`paid_leave_request.*`の集約ID、旧`paid_leave.*`の集約ID、
 * `paid_leave_usage_*`の申請IDのいずれも同じ値)。入力は申請IDごとに排他の3系統:
 * - legacy_paid: 旧`paid_leave.requested/request_approved/request_returned/request_cancelled`
 *   (集約ID=申請ID。廃止済みで、再生用に残置されたイベント)。
 * - paid_account: cutover後〜本変更前の`paid_leave_account.usage_designated/usage_confirmed/
 *   usage_cancelled`+`workflow_request.returned`(消化記録IDは`paid_leave_request_usage_links`で、
 *   ワークフローIDは休暇申請文脈の`leave_request_workflow_links`で申請IDへ対応付ける)。
 * - paid_request: 本変更後の`paid_leave_request.requested/resubmitted/approved/returned/cancelled/migrated`。
 *
 * 系統の規則(AttendanceDayLeaveProjector と同じ):
 * - 作成イベント(旧requested・Designated・新requested・migrated)は、既存行が無い・系統が未設定(NULL。
 *   本変更前にPaidLeaveUsageAllocationProjectorが作った行)・同じ系統のときだけ適用する。
 *   新系統の作成イベント(requested・migrated)は別系統の行も引き継いで上書きする(takeover)。
 * - 状態のイベントは、既存行があり系統が一致する(またはNULL)ときだけ適用する。行が無ければ何もしない
 *   (再生の順序に依存させない)。
 * - 新系統(paid_request)の行は、旧系統・cutover後の系統のイベントで変えない。
 * - 「行を作ったときの系統」は`input_source`に保持する。
 *
 * 全て upsert/update で書くため、同じイベント列を再適用しても、空から再生成しても同じ状態になる。
 * 差戻し・取消でも行は削除しない(statusを変える)。
 */
class PaidLeaveRequestProjector extends Projector
{
    // ---- 旧系統(旧paid_leave.*) ----

    public function onPaidLeaveRequested(PaidLeaveRequested $event): void
    {
        $this->create($event->aggregateRootUuid(), PaidLeaveRequest::SOURCE_LEGACY_PAID, [
            'user_id' => $event->userId,
            'approver_user_id' => $event->approverUserId,
            'status' => PaidLeaveRequestStatus::SUBMITTED,
            'leave_type' => $event->leaveType,
            'target_date' => $event->targetDate,
            'hours' => $event->hours,
            'requested_days' => $event->requestedDays,
            'reason' => $event->reason,
            'request_group_id' => $event->requestGroupId,
            'submitted_at' => $event->createdAt(),
            'approved_at' => null,
            'returned_at' => null,
            'cancelled_at' => null,
        ]);
    }

    public function onPaidLeaveRequestApproved(PaidLeaveRequestApproved $event): void
    {
        $this->transition($event->aggregateRootUuid(), PaidLeaveRequest::SOURCE_LEGACY_PAID, [
            'status' => PaidLeaveRequestStatus::APPROVED,
            'approved_at' => $event->createdAt(),
        ]);
    }

    public function onPaidLeaveRequestReturned(PaidLeaveRequestReturned $event): void
    {
        $this->transition($event->aggregateRootUuid(), PaidLeaveRequest::SOURCE_LEGACY_PAID, [
            'status' => PaidLeaveRequestStatus::RETURNED,
            'returned_at' => $event->createdAt(),
        ]);
    }

    public function onPaidLeaveRequestCancelled(PaidLeaveRequestCancelled $event): void
    {
        $this->transition($event->aggregateRootUuid(), PaidLeaveRequest::SOURCE_LEGACY_PAID, [
            'status' => PaidLeaveRequestStatus::CANCELLED,
            'cancelled_at' => $event->createdAt(),
        ]);
    }

    // ---- cutover後の系統(paid_leave_account.usage_* + workflow_request.returned) ----

    public function onPaidLeaveUsageDesignated(PaidLeaveUsageDesignated $event): void
    {
        // 有給の申請IDを持たない消化記録は申請行を作らない。
        if ($event->paidLeaveRequestId === null) {
            return;
        }

        $applied = $this->create($event->paidLeaveRequestId, PaidLeaveRequest::SOURCE_PAID_ACCOUNT, [
            'user_id' => $event->aggregateRootUuid(),
            'approver_user_id' => $this->approverOf($event->approverUserId, $event->aggregateRootUuid()),
            'status' => PaidLeaveRequestStatus::SUBMITTED,
            'leave_type' => $event->usageType,
            'target_date' => $event->usedOn,
            'hours' => $event->hours,
            'requested_days' => $event->usedDays,
            'reason' => $event->reason,
            'request_group_id' => $event->requestGroupId,
            'submitted_at' => $event->createdAt(),
            'approved_at' => null,
            'returned_at' => null,
            'cancelled_at' => null,
        ]);

        if ($applied) {
            PaidLeaveRequestUsageLink::query()->updateOrCreate(
                ['usage_id' => $event->usageId],
                ['paid_leave_request_id' => $event->paidLeaveRequestId],
            );
        }
    }

    public function onPaidLeaveUsageConfirmed(PaidLeaveUsageConfirmed $event): void
    {
        $this->transitionByUsage($event->usageId, [
            'status' => PaidLeaveRequestStatus::APPROVED,
            'approved_at' => $event->createdAt(),
        ]);
    }

    public function onPaidLeaveUsageCancelled(PaidLeaveUsageCancelled $event): void
    {
        $this->transitionByUsage($event->usageId, [
            'status' => PaidLeaveRequestStatus::CANCELLED,
            'cancelled_at' => $event->createdAt(),
        ]);
    }

    /**
     * 差戻し(returned)。ワークフローIDから有給申請IDを引く(休暇申請文脈の対応表。
     * 有給のdraftedのsubject由来で、LeaveRequestWorkflowLinkProjectorが作る)。
     */
    public function onWorkflowRequestReturned(WorkflowRequestReturned $event): void
    {
        $requestId = LeaveRequestWorkflowLink::query()
            ->whereKey($event->aggregateRootUuid())
            ->where('leave_kind', LeaveRequestWorkflowLink::KIND_PAID)
            ->value('leave_request_id');

        if ($requestId === null) {
            return;
        }

        $this->transition($requestId, PaidLeaveRequest::SOURCE_PAID_ACCOUNT, [
            'status' => PaidLeaveRequestStatus::RETURNED,
            'returned_at' => $event->createdAt(),
        ]);
    }

    // ---- 新系統(paid_leave_request.*) ----

    public function onPaidLeaveRequestLifecycleRequested(PaidLeaveRequestLifecycleRequested $event): void
    {
        $this->create($event->aggregateRootUuid(), PaidLeaveRequest::SOURCE_PAID_REQUEST, [
            'user_id' => $event->userId,
            'approver_user_id' => $this->approverOf($event->approverUserId, $event->userId),
            'status' => PaidLeaveRequestStatus::SUBMITTED,
            'leave_type' => $event->leaveType,
            'target_date' => $event->targetDate,
            'hours' => $event->hours,
            'requested_days' => $event->requestedDays,
            'reason' => $event->reason,
            'request_group_id' => $event->requestGroupId,
            'submitted_at' => $event->createdAt(),
            'approved_at' => null,
            'returned_at' => null,
            'cancelled_at' => null,
        ], takeover: true);
    }

    public function onPaidLeaveRequestLifecycleResubmitted(PaidLeaveRequestLifecycleResubmitted $event): void
    {
        $this->transition($event->aggregateRootUuid(), PaidLeaveRequest::SOURCE_PAID_REQUEST, [
            'status' => PaidLeaveRequestStatus::SUBMITTED,
            'submitted_at' => $event->createdAt(),
        ]);
    }

    public function onPaidLeaveRequestLifecycleApproved(PaidLeaveRequestLifecycleApproved $event): void
    {
        $this->transition($event->aggregateRootUuid(), PaidLeaveRequest::SOURCE_PAID_REQUEST, [
            'status' => PaidLeaveRequestStatus::APPROVED,
            'approved_at' => $event->createdAt(),
        ]);
    }

    public function onPaidLeaveRequestLifecycleReturned(PaidLeaveRequestLifecycleReturned $event): void
    {
        $this->transition($event->aggregateRootUuid(), PaidLeaveRequest::SOURCE_PAID_REQUEST, [
            'status' => PaidLeaveRequestStatus::RETURNED,
            'returned_at' => $event->createdAt(),
        ]);
    }

    public function onPaidLeaveRequestLifecycleCancelled(PaidLeaveRequestLifecycleCancelled $event): void
    {
        $this->transition($event->aggregateRootUuid(), PaidLeaveRequest::SOURCE_PAID_REQUEST, [
            'status' => PaidLeaveRequestStatus::CANCELLED,
            'cancelled_at' => $event->createdAt(),
        ]);
    }

    /**
     * 本変更前の申請の引き継ぎ。以後この申請IDは新系統だけで状態を作る(旧系統・cutover後の系統は
     * 切り替えで無視される)。引き継ぎ時点の状態(status)を入れる。日時は、イベントに元の日時
     * (submittedAt等)があればその値、無ければ記録日時(createdAt())を使う。
     * 状態に対応する時刻列(approved_at等)は、その状態のときだけ入れる(元の日時がある場合を除く)。
     */
    public function onPaidLeaveRequestLifecycleMigrated(PaidLeaveRequestLifecycleMigrated $event): void
    {
        $at = $event->createdAt();

        $this->create($event->aggregateRootUuid(), PaidLeaveRequest::SOURCE_PAID_REQUEST, [
            'user_id' => $event->userId,
            'approver_user_id' => $this->approverOf($event->approverUserId, $event->userId),
            'status' => $event->status,
            'leave_type' => $event->leaveType,
            'target_date' => $event->targetDate,
            'hours' => $event->hours,
            'requested_days' => $event->requestedDays,
            'reason' => $event->reason,
            'request_group_id' => $event->requestGroupId,
            'submitted_at' => $event->submittedAt ?? $at,
            'approved_at' => $event->approvedAt ?? ($event->status === PaidLeaveRequestStatus::APPROVED ? $at : null),
            'returned_at' => $event->returnedAt ?? ($event->status === PaidLeaveRequestStatus::RETURNED ? $at : null),
            'cancelled_at' => $event->cancelledAt ?? ($event->status === PaidLeaveRequestStatus::CANCELLED ? $at : null),
        ], takeover: true);
    }

    // ---- 補助 ----

    /**
     * 承認者が無い申請(承認不要時)は申請者をプレースホルダにする(コントローラの現行と同じ。
     * approver_user_idはNOT NULLの外部キーのため)。
     */
    private function approverOf(?string $approverUserId, string $userId): string
    {
        return $approverUserId ?? $userId;
    }

    /**
     * 作成イベントで行を作る(既存行があれば全項目を上書きする)。takeover=falseのとき、既存行が
     * 別の系統(NULLを除く)なら何もしない。適用したら true。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function create(string $requestId, string $source, array $attributes, bool $takeover = false): bool
    {
        $existing = PaidLeaveRequest::query()->find($requestId);
        if (! $takeover && $existing !== null && ! $this->canWrite($existing, $source)) {
            return false;
        }

        PaidLeaveRequest::query()->updateOrCreate(
            ['id' => $requestId],
            $attributes + ['input_source' => $source],
        );

        return true;
    }

    /**
     * 既存行が同じ系統(またはNULL)のときだけ状態を更新する。行が無い・別の系統なら何もしない。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function transition(string $requestId, string $source, array $attributes): void
    {
        $request = PaidLeaveRequest::query()->find($requestId);
        if ($request === null || ! $this->canWrite($request, $source)) {
            return;
        }

        $request->update($attributes);
    }

    /**
     * 消化記録ID→有給申請IDの対応で、cutover後の系統の行の状態を更新する。
     * 対応が無い(有給の申請IDを持たない消化記録など)なら何もしない。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function transitionByUsage(string $usageId, array $attributes): void
    {
        $requestId = PaidLeaveRequestUsageLink::query()->whereKey($usageId)->value('paid_leave_request_id');
        if ($requestId === null) {
            return;
        }

        $this->transition($requestId, PaidLeaveRequest::SOURCE_PAID_ACCOUNT, $attributes);
    }

    private function canWrite(PaidLeaveRequest $request, string $source): bool
    {
        return $request->input_source === null || $request->input_source === $source;
    }
}
