<?php

namespace App\Domain\RequestCenter\Projectors;

use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestApproved;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestCancelled;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequested;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestReturned;
use App\Domain\ExpenseClaim\Events\ExpenseClaimApproved;
use App\Domain\ExpenseClaim\Events\ExpenseClaimCancelled;
use App\Domain\ExpenseClaim\Events\ExpenseClaimDeleted;
use App\Domain\ExpenseClaim\Events\ExpenseClaimDrafted;
use App\Domain\ExpenseClaim\Events\ExpenseClaimReturned;
use App\Domain\ExpenseClaim\Events\ExpenseClaimSubmitted;
use App\Domain\ExpenseClaim\Events\ExpenseClaimTitleUpdated;
use App\Domain\PaidLeave\Events\PaidLeaveRequestApproved;
use App\Domain\PaidLeave\Events\PaidLeaveRequestCancelled;
use App\Domain\PaidLeave\Events\PaidLeaveRequested;
use App\Domain\PaidLeave\Events\PaidLeaveRequestReturned;
use App\Domain\Workflow\Events\WorkflowRequestApproved;
use App\Domain\Workflow\Events\WorkflowRequestCancelled;
use App\Domain\Workflow\Events\WorkflowRequestDrafted;
use App\Domain\Workflow\Events\WorkflowRequestReturned;
use App\Domain\Workflow\Events\WorkflowRequestSubmitted;
use App\Models\CompensatoryLeaveRequestStatus;
use App\Models\ExpenseClaimStatus;
use App\Models\PaidLeaveRequestStatus;
use App\Models\RequestCenterItem;
use App\Models\RequestCenterItemType;
use App\Models\WorkflowRequestStatus;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * 「申請センター」画面向けの横断Projection(request_center_items)を、
 * paid_leave.* / compensatory_leave.* / expense_claim.* / workflow_request.* の
 * 申請系(承認ワークフロー)イベントから作成・更新する。既存4ドメインのイベント・
 * Projectorには一切手を加えず、既存イベントを購読するだけで構成する
 * (対象外: 既存Projectorの変更)。
 *
 * 【責務の境界】このProjectorが持たせるのは承認ワークフロー共通の情報
 * (申請種別・ステータス・申請者・承認者・タイトル・提出日時)と、詳細画面へのポインタ
 * (request_type + source_id)だけである。各業務ドメイン固有の未確定ステート・金額集計・
 * 残高計算(例: expense_claims.total_amountの明細集計、paid_leave_grantsの残高計算)は
 * 一切複製しない。詳細な業務データは request_type + source_id を使って元の業務ドメインの
 * API/画面から取得させる設計とする。
 *
 * 【「申請が存在する場合のみ」のビュー】このProjectorはPaidLeaveRequested /
 * CompensatoryLeaveRequested / ExpenseClaimDrafted / WorkflowRequestDrafted
 * (=申請という行為そのものの発生)を購読して初めて行を作る。管理者による手動付与
 * (compensatory_leave.manually_granted 等、承認ワークフローを経由しない業務データ)は
 * 対象イベントに含めていないため、この一覧には現れない
 * (経費精算のapproval_skip_threshold等による承認者確認の自動省略は、submitted/approved
 * イベント自体は引き続き発生するため、通常の申請として扱われる)。
 *
 * 各ハンドラは対象行をupdateOrCreateするだけの冪等な処理とし、Projection Tableを
 * 空にして全イベントを再生しても同じ結果になるようにする(.claude/skills/add-projection)。
 */
class RequestCenterItemProjector extends Projector
{
    // --- 有給休暇 (paid_leave.*) ---

    public function onPaidLeaveRequested(PaidLeaveRequested $event): void
    {
        RequestCenterItem::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'request_type' => RequestCenterItemType::PAID_LEAVE,
                'source_id' => $event->aggregateRootUuid(),
                'status' => PaidLeaveRequestStatus::SUBMITTED,
                'requester_id' => $event->userId,
                'approver_id' => $event->approverUserId,
                'title' => '有給休暇申請',
                'submitted_at' => $event->createdAt(),
            ],
        );
    }

    public function onPaidLeaveRequestApproved(PaidLeaveRequestApproved $event): void
    {
        RequestCenterItem::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => PaidLeaveRequestStatus::APPROVED,
        ]);
    }

    public function onPaidLeaveRequestReturned(PaidLeaveRequestReturned $event): void
    {
        RequestCenterItem::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => PaidLeaveRequestStatus::RETURNED,
        ]);
    }

    public function onPaidLeaveRequestCancelled(PaidLeaveRequestCancelled $event): void
    {
        RequestCenterItem::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => PaidLeaveRequestStatus::CANCELLED,
        ]);
    }

    // --- 代休消化 (compensatory_leave.*) ---
    // (compensatory_leave.manually_granted 等の付与系イベントは申請を経由しないため購読しない)

    public function onCompensatoryLeaveRequested(CompensatoryLeaveRequested $event): void
    {
        RequestCenterItem::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'request_type' => RequestCenterItemType::COMPENSATORY_LEAVE,
                'source_id' => $event->aggregateRootUuid(),
                'status' => CompensatoryLeaveRequestStatus::SUBMITTED,
                'requester_id' => $event->userId,
                'approver_id' => $event->approverUserId,
                'title' => '代休消化申請',
                'submitted_at' => $event->createdAt(),
            ],
        );
    }

    public function onCompensatoryLeaveRequestApproved(CompensatoryLeaveRequestApproved $event): void
    {
        RequestCenterItem::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => CompensatoryLeaveRequestStatus::APPROVED,
        ]);
    }

    public function onCompensatoryLeaveRequestReturned(CompensatoryLeaveRequestReturned $event): void
    {
        RequestCenterItem::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => CompensatoryLeaveRequestStatus::RETURNED,
        ]);
    }

    public function onCompensatoryLeaveRequestCancelled(CompensatoryLeaveRequestCancelled $event): void
    {
        RequestCenterItem::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => CompensatoryLeaveRequestStatus::CANCELLED,
        ]);
    }

    // --- 経費精算 (expense_claim.*) ---

    /**
     * 下書き作成時点ではタイトル・承認者とも未確定のため仮の値で行を作る
     * (ExpenseClaimTitleUpdated/submitted以降で更新される)。
     */
    public function onExpenseClaimDrafted(ExpenseClaimDrafted $event): void
    {
        RequestCenterItem::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'request_type' => RequestCenterItemType::EXPENSE_CLAIM,
                'source_id' => $event->aggregateRootUuid(),
                'status' => ExpenseClaimStatus::DRAFT,
                'requester_id' => $event->employeeId,
                'approver_id' => null,
                'title' => '経費精算',
                'submitted_at' => null,
            ],
        );
    }

    public function onExpenseClaimTitleUpdated(ExpenseClaimTitleUpdated $event): void
    {
        RequestCenterItem::query()->whereKey($event->aggregateRootUuid())->update([
            'title' => $event->title ?? '経費精算',
        ]);
    }

    public function onExpenseClaimSubmitted(ExpenseClaimSubmitted $event): void
    {
        RequestCenterItem::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => ExpenseClaimStatus::IN_REVIEW,
            'approver_id' => $event->approverUserId,
            'submitted_at' => $event->createdAt(),
        ]);
    }

    public function onExpenseClaimApproved(ExpenseClaimApproved $event): void
    {
        RequestCenterItem::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => ExpenseClaimStatus::APPROVED,
        ]);
    }

    public function onExpenseClaimReturned(ExpenseClaimReturned $event): void
    {
        RequestCenterItem::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => ExpenseClaimStatus::RETURNED,
        ]);
    }

    public function onExpenseClaimCancelled(ExpenseClaimCancelled $event): void
    {
        RequestCenterItem::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => ExpenseClaimStatus::CANCELLED,
        ]);
    }

    /**
     * 不要な下書きの削除(ExpenseClaimProjector::onExpenseClaimDeletedと同じ考え方)。
     */
    public function onExpenseClaimDeleted(ExpenseClaimDeleted $event): void
    {
        RequestCenterItem::query()->whereKey($event->aggregateRootUuid())->delete();
    }

    // --- 汎用申請 (workflow_request.*) ---

    public function onWorkflowRequestDrafted(WorkflowRequestDrafted $event): void
    {
        RequestCenterItem::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'request_type' => RequestCenterItemType::WORKFLOW,
                'source_id' => $event->aggregateRootUuid(),
                'status' => WorkflowRequestStatus::DRAFT,
                'requester_id' => $event->applicantUserId,
                'approver_id' => $event->approverUserId,
                'title' => $event->title,
                'submitted_at' => null,
            ],
        );
    }

    public function onWorkflowRequestSubmitted(WorkflowRequestSubmitted $event): void
    {
        RequestCenterItem::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => WorkflowRequestStatus::SUBMITTED,
            'approver_id' => $event->approverUserId,
            'submitted_at' => $event->createdAt(),
        ]);
    }

    public function onWorkflowRequestApproved(WorkflowRequestApproved $event): void
    {
        RequestCenterItem::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => WorkflowRequestStatus::APPROVED,
        ]);
    }

    public function onWorkflowRequestReturned(WorkflowRequestReturned $event): void
    {
        RequestCenterItem::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => WorkflowRequestStatus::RETURNED,
        ]);
    }

    public function onWorkflowRequestCancelled(WorkflowRequestCancelled $event): void
    {
        RequestCenterItem::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => WorkflowRequestStatus::CANCELLED,
        ]);
    }
}
