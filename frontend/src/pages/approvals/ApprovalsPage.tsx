import { useState } from 'react'
import { ApprovalDetailPanel } from '../../components/ApprovalDetailPanel/ApprovalDetailPanel'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Pagination } from '../../components/Pagination/Pagination'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '../../components/ui/dialog'
import { NativeSelect } from '../../components/ui/native-select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import { YearMonthPicker } from '../../components/YearMonthPicker/YearMonthPicker'
import type { WorkflowRequest, WorkflowRequestSubjectType } from '../../api/types'
import type { FetchWorkflowRequestsToApproveOptions } from '../../api/workflowRequests'
import { useApproveApprovalItem, useReturnApprovalItem } from '../../hooks/useApprovals'
import { useWorkflowRequest, useWorkflowRequestsToApprove } from '../../hooks/useWorkflowRequests'
import { attendanceMonthStatusLabel, expenseClaimStatusLabel, workflowRequestStatusLabel } from '../../utils/statusLabels'

const DEFAULT_STATUS: NonNullable<FetchWorkflowRequestsToApproveOptions['status']> = 'submitted'

const STATUS_FILTER_OPTIONS: Array<{ value: NonNullable<FetchWorkflowRequestsToApproveOptions['status']>; label: string }> = [
  { value: 'submitted', label: '承認待ち' },
  { value: 'approved', label: '承認済み' },
  { value: 'returned', label: '差戻し済み' },
  { value: 'cancelled', label: '取消' },
  { value: 'all', label: 'すべて' },
]

const SUBJECT_TYPE_LABELS: Record<Exclude<WorkflowRequestSubjectType, null>, string> = {
  attendance_month: '勤怠',
  expense_claim: '経費',
  paid_leave_request: '有給',
  special_leave_request: '特別休暇',
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
  const [status, setStatus] = useState<NonNullable<FetchWorkflowRequestsToApproveOptions['status']>>(DEFAULT_STATUS)
  const [yearMonth, setYearMonth] = useState<string | undefined>(undefined)
  const [page, setPage] = useState(1)
  const { data, isLoading, error } = useWorkflowRequestsToApprove({ status, yearMonth, page })
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const {
    data: selectedRequest,
    isLoading: isLoadingDetail,
    error: detailError,
  } = useWorkflowRequest(selectedId ?? '')
  const approveItem = useApproveApprovalItem()
  const returnItem = useReturnApprovalItem()

  const requests = data?.data ?? []
  const isFiltered = status !== DEFAULT_STATUS || Boolean(yearMonth)

  function closePanel() {
    setSelectedId(null)
    approveItem.reset()
    returnItem.reset()
  }

  function handleFilterChange(next: { status?: NonNullable<FetchWorkflowRequestsToApproveOptions['status']>; yearMonth?: string }) {
    if (next.status !== undefined) setStatus(next.status)
    if (next.yearMonth !== undefined) setYearMonth(next.yearMonth || undefined)
    setPage(1)
  }

  function clearFilters() {
    setStatus(DEFAULT_STATUS)
    setYearMonth(undefined)
    setPage(1)
  }

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="承認待ち一覧の取得に失敗しました。" />

  return (
    <Card title="承認待ち">
      <div className="mb-4 flex flex-wrap items-end gap-4">
        <div className="w-40">
          <FormField label="状態" htmlFor="approvals-status">
            <NativeSelect
              id="approvals-status"
              value={status}
              onChange={(event) => handleFilterChange({ status: event.target.value as NonNullable<FetchWorkflowRequestsToApproveOptions['status']> })}
            >
              {STATUS_FILTER_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </NativeSelect>
          </FormField>
        </div>
        <div className="w-48">
          <FormField label="年月" htmlFor="approvals-year-month">
            <YearMonthPicker
              id="approvals-year-month"
              value={yearMonth}
              onChange={(value) => handleFilterChange({ yearMonth: value ?? '' })}
            />
          </FormField>
        </div>
        {isFiltered && (
          <Button variant="secondary" onClick={clearFilters}>
            フィルターをクリア
          </Button>
        )}
      </div>

      {requests.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          {isFiltered ? '条件に一致する申請はありません。' : '承認待ちの申請はありません。'}
        </p>
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

      {data && <Pagination currentPage={data.meta.current_page} lastPage={data.meta.last_page} total={data.meta.total} onPageChange={setPage} />}

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
