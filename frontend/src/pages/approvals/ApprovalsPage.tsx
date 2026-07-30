import { useState } from 'react'
import { ApprovalDetailPanel } from '../../components/ApprovalDetailPanel/ApprovalDetailPanel'
import { Badge } from '../../components/Badge/Badge'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '../../components/ui/dialog'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import type { WorkflowRequest, WorkflowRequestSubjectType } from '../../api/types'
import { useApproveApprovalItem, useReturnApprovalItem } from '../../hooks/useApprovals'
import { useWorkflowRequest, useWorkflowRequestsToApprove } from '../../hooks/useWorkflowRequests'
import { attendanceMonthStatusLabel, expenseClaimStatusLabel, workflowRequestStatusLabel } from '../../utils/statusLabels'

const SUBJECT_TYPE_LABELS: Record<Exclude<WorkflowRequestSubjectType, null>, string> = {
  attendance_month: '勤怠',
  expense_claim: '経費',
}

function subjectTypeLabel(subjectType: WorkflowRequestSubjectType): string {
  return subjectType ? SUBJECT_TYPE_LABELS[subjectType] : '申請'
}

/** 一覧行のステータスバッジ。詳細取得前でも一覧のsubject_summaryだけでラベル付けできるように、
 *  subject_type別に対応するステータス種別のラベル関数へ振り分ける。 */
function rowStatusMeta(request: WorkflowRequest) {
  if (request.subject_type === 'attendance_month' && request.subject_summary && 'year_month' in request.subject_summary) {
    return attendanceMonthStatusLabel(request.subject_summary.status)
  }
  if (request.subject_type === 'expense_claim' && request.subject_summary && 'total_amount' in request.subject_summary) {
    return expenseClaimStatusLabel(request.subject_summary.status)
  }
  return workflowRequestStatusLabel(request.status)
}

function formatSubmittedAt(value: string | null): string {
  if (!value) return '-'
  return new Date(value).toLocaleDateString('ja-JP')
}

/**
 * 月次勤怠承認・経費精算承認・汎用申請承認を1つの一覧に統合した承認者向け画面。
 * 行を選ぶとその場に詳細パネル(モーダル)を開き、`subject_type`に応じて
 * 表示内容(ApprovalDetailPanel)と承認・差戻しの呼び先API(useApprovalActions)を
 * 切り替える(オブジェクト指向UI: どの種別の申請かをまず選び、その後で内容確認・操作する)。
 */
export function ApprovalsPage() {
  const { data, isLoading, error } = useWorkflowRequestsToApprove()
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const {
    data: selectedRequest,
    isLoading: isLoadingDetail,
    error: detailError,
  } = useWorkflowRequest(selectedId ?? '')
  const approveItem = useApproveApprovalItem()
  const returnItem = useReturnApprovalItem()

  const requests = data?.data ?? []

  function closePanel() {
    setSelectedId(null)
    approveItem.reset()
    returnItem.reset()
  }

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="承認待ち一覧の取得に失敗しました。" />

  return (
    <Card title="承認待ち">
      {requests.length === 0 ? (
        <p className="text-sm text-muted-foreground">承認待ちの申請はありません。</p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>種別</TableHead>
              <TableHead>タイトル</TableHead>
              <TableHead>申請者</TableHead>
              <TableHead>状態</TableHead>
              <TableHead>提出日</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {requests.map((request) => {
              const { label, tone } = rowStatusMeta(request)
              return (
                <TableRow key={request.id}>
                  <TableCell>
                    <Badge tone="neutral">{subjectTypeLabel(request.subject_type ?? null)}</Badge>
                  </TableCell>
                  <TableCell>
                    <button
                      type="button"
                      className="font-medium text-foreground hover:text-primary hover:underline"
                      onClick={() => setSelectedId(request.id)}
                    >
                      {request.title}
                    </button>
                  </TableCell>
                  <TableCell className="text-muted-foreground">{request.applicant?.name}</TableCell>
                  <TableCell>
                    <Badge tone={tone}>{label}</Badge>
                  </TableCell>
                  <TableCell className="text-muted-foreground">{formatSubmittedAt(request.submitted_at)}</TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      )}

      <Dialog open={selectedId !== null} onOpenChange={(open) => !open && closePanel()}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>{selectedRequest?.title ?? '申請の詳細'}</DialogTitle>
          </DialogHeader>
          {isLoadingDetail ? (
            <LoadingState />
          ) : detailError ? (
            <ErrorMessage error={detailError} fallback="申請の取得に失敗しました。" />
          ) : selectedRequest ? (
            <ApprovalDetailPanel
              request={selectedRequest}
              approveIsPending={approveItem.isPending}
              returnIsPending={returnItem.isPending}
              actionError={approveItem.error ?? returnItem.error}
              onApprove={() => approveItem.mutate(selectedRequest)}
              onReturn={(comment) => returnItem.mutate({ request: selectedRequest, comment })}
            />
          ) : null}
        </DialogContent>
      </Dialog>
    </Card>
  )
}
