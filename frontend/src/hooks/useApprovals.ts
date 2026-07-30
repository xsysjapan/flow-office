import { useMutation, useQueryClient } from '@tanstack/react-query'
import { approveExpenseClaim, returnExpenseClaim } from '../api/expenseClaims'
import { approveMonth, returnMonth } from '../api/attendance'
import { approveWorkflowRequest, returnWorkflowRequest } from '../api/workflowRequests'
import type { WorkflowRequest } from '../api/types'

/**
 * 統合承認画面(pages/approvals/ApprovalsPage)向け: 汎用申請一覧の1行(WorkflowRequest)を
 * subject_type(null/attendance_month/expense_claim)に応じて適切なドメインAPIへ振り分けて
 * 承認・差戻しする。subject_type!=nullの行は`/workflow-requests/{id}/approve`・`/return`を
 * 呼んではならない(汎用申請専用で、対象ドメインの実データを更新しない)ため、
 * `request.subject`(GET /workflow-requests/{id}のみに含まれる詳細)のidを使って
 * 対象ドメインの既存の承認・差戻しエンドポイントを呼ぶ。
 */
function approveBySubject(request: WorkflowRequest): Promise<unknown> {
  if (request.subject_type === 'attendance_month' && request.subject?.type === 'attendance_month') {
    return approveMonth(request.subject.id)
  }
  if (request.subject_type === 'expense_claim' && request.subject?.type === 'expense_claim') {
    return approveExpenseClaim(request.subject.id)
  }
  return approveWorkflowRequest(request.id)
}

function returnBySubject(request: WorkflowRequest, comment: string): Promise<unknown> {
  if (request.subject_type === 'attendance_month' && request.subject?.type === 'attendance_month') {
    return returnMonth(request.subject.id, comment)
  }
  if (request.subject_type === 'expense_claim' && request.subject?.type === 'expense_claim') {
    return returnExpenseClaim(request.subject.id, comment)
  }
  return returnWorkflowRequest(request.id, comment)
}

/** 承認・差戻し後、影響しうる一覧・詳細のキャッシュ(汎用申請・経費精算・月次勤怠)を
 *  まとめて無効化する。どのドメインを更新したかに関わらず全て無効化してよい
 *  (無駄な再フェッチは発生するが、対応関係の分岐をキャッシュキー側にまで持たせない)。 */
function useInvalidateApprovals() {
  const queryClient = useQueryClient()

  return () => {
    void queryClient.invalidateQueries({ queryKey: ['workflow-requests'] })
    void queryClient.invalidateQueries({ queryKey: ['expense-claims'] })
    void queryClient.invalidateQueries({ queryKey: ['attendance', 'months'] })
  }
}

export function useApproveApprovalItem() {
  const invalidate = useInvalidateApprovals()

  return useMutation({
    mutationFn: (request: WorkflowRequest) => approveBySubject(request),
    onSuccess: invalidate,
  })
}

export function useReturnApprovalItem() {
  const invalidate = useInvalidateApprovals()

  return useMutation({
    mutationFn: ({ request, comment }: { request: WorkflowRequest; comment: string }) =>
      returnBySubject(request, comment),
    onSuccess: invalidate,
  })
}
