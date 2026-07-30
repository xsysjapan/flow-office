<?php

namespace App\Domain\Workflow\Projectors;

use App\Domain\Attendance\Events\AttendanceMonthApproved;
use App\Domain\Attendance\Events\AttendanceMonthReturned;
use App\Domain\Attendance\Events\AttendanceMonthSubmitted;
use App\Domain\ExpenseClaim\Events\ExpenseClaimApproved;
use App\Domain\ExpenseClaim\Events\ExpenseClaimReturned;
use App\Domain\ExpenseClaim\Events\ExpenseClaimSubmitted;
use App\Models\ExpenseClaim;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * 月次勤怠申請(attendance_month.*)・経費精算申請(expense_claim.*)の提出/承認/差戻し
 * 本体イベントを購読し、workflow_requestsへ横断一覧用の行をupsertする。
 *
 * これらのドメインは独立した集約・ステータス系列(attendance_months/expense_claims)を
 * 正データとして持つため、このProjectorが作る行は「一覧・詳細表示専用の派生データ」
 * (subject_type/subject_idで元エンティティを指す)であり、Attendance/ExpenseClaim側の
 * Handler・Aggregate・Eventファイルには一切変更を加えない(読み取り専用で購読するだけ)。
 *
 * 主キー(workflow_requests.id)は本Projectorが新規生成するUUIDであり、
 * attendance_months.id/expense_claims.id(subject_id)とは別物。提出時に作成し、
 * 承認・差戻し時は(subject_type, subject_id)で既存行を検索して更新する
 * (承認/差戻しイベントは対象の年月・申請者情報を持たないため)。
 */
class WorkflowRequestSubjectProjector extends Projector
{
    private const ATTENDANCE_MONTH = 'attendance_month';

    private const EXPENSE_CLAIM = 'expense_claim';

    public function onAttendanceMonthSubmitted(AttendanceMonthSubmitted $event): void
    {
        $request = $this->findOrNewSubjectRequest(self::ATTENDANCE_MONTH, $event->aggregateRootUuid());

        $request->fill([
            'title' => "{$event->yearMonth} 月次勤怠",
            'applicant_user_id' => $event->userId,
            'approver_user_id' => $event->approverUserId,
            'status' => WorkflowRequestStatus::SUBMITTED,
            'form_data' => [],
            'submitted_at' => $event->createdAt(),
            'approved_at' => null,
            'returned_at' => null,
            'cancelled_at' => null,
        ])->save();
    }

    public function onAttendanceMonthApproved(AttendanceMonthApproved $event): void
    {
        $this->updateSubjectRequest(self::ATTENDANCE_MONTH, $event->aggregateRootUuid(), [
            'status' => WorkflowRequestStatus::APPROVED,
            'approved_at' => $event->createdAt(),
        ]);
    }

    public function onAttendanceMonthReturned(AttendanceMonthReturned $event): void
    {
        $this->updateSubjectRequest(self::ATTENDANCE_MONTH, $event->aggregateRootUuid(), [
            'status' => WorkflowRequestStatus::RETURNED,
            'returned_at' => $event->createdAt(),
        ]);
    }

    public function onExpenseClaimSubmitted(ExpenseClaimSubmitted $event): void
    {
        $claim = ExpenseClaim::query()->find($event->aggregateRootUuid());

        $request = $this->findOrNewSubjectRequest(self::EXPENSE_CLAIM, $event->aggregateRootUuid());

        $request->fill([
            'title' => $claim?->title ?? '経費精算申請',
            'applicant_user_id' => $event->submittedByUserId,
            'approver_user_id' => $event->approverUserId,
            'status' => WorkflowRequestStatus::SUBMITTED,
            'form_data' => [],
            'submitted_at' => $event->createdAt(),
            'approved_at' => null,
            'returned_at' => null,
            'cancelled_at' => null,
        ])->save();
    }

    public function onExpenseClaimApproved(ExpenseClaimApproved $event): void
    {
        $this->updateSubjectRequest(self::EXPENSE_CLAIM, $event->aggregateRootUuid(), [
            'status' => WorkflowRequestStatus::APPROVED,
            'approved_at' => $event->createdAt(),
        ]);
    }

    public function onExpenseClaimReturned(ExpenseClaimReturned $event): void
    {
        $this->updateSubjectRequest(self::EXPENSE_CLAIM, $event->aggregateRootUuid(), [
            'status' => WorkflowRequestStatus::RETURNED,
            'returned_at' => $event->createdAt(),
        ]);
    }

    private function findOrNewSubjectRequest(string $subjectType, string $subjectId): WorkflowRequest
    {
        return WorkflowRequest::query()->firstOrNew([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function updateSubjectRequest(string $subjectType, string $subjectId, array $attributes): void
    {
        WorkflowRequest::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->update($attributes);
    }
}
