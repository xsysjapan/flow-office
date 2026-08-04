import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { ApprovalDetailPanel } from '../../components/ApprovalDetailPanel/ApprovalDetailPanel'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Pagination } from '../../components/Pagination/Pagination'
import { Checkbox } from '../../components/ui/checkbox'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '../../components/ui/dialog'
import { NativeSelect } from '../../components/ui/native-select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import { YearMonthPicker } from '../../components/YearMonthPicker/YearMonthPicker'
import type { WorkflowRequest, WorkflowRequestSubjectType } from '../../api/types'
import { fetchWorkflowRequest, type FetchWorkflowRequestsToApproveOptions } from '../../api/workflowRequests'
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
  shift_swap_request: '振替休日',
}

function subjectTypeLabel(subjectType: WorkflowRequestSubjectType): string {
  return subjectType ? SUBJECT_TYPE_LABELS[subjectType] : '申請'
}

/**
 * 一覧行に表示する補足情報(種別バッジだけでは判別できない内容、特に特別休暇の
 * 具体的な休暇種別名)。詳細モーダルを開かなくても一覧だけで内容が分かるようにする。
 * 一覧のsubject_summaryだけで組み立て、詳細取得(subject)を待たない。
 */
function subjectSubtitle(request: WorkflowRequest): string | null {
  const summary = request.subject_summary
  if (!summary) return null

  if (request.subject_type === 'attendance_month' && 'year_month' in summary) {
    return summary.year_month
  }
  if (request.subject_type === 'expense_claim' && 'total_amount' in summary) {
    const amount = `${summary.total_amount.toLocaleString()}円`
    return request.applicant?.name ? `${request.applicant.name} / ${amount}` : amount
  }
  if (request.subject_type === 'special_leave_request' && 'special_leave_type_name' in summary) {
    return [summary.target_date, summary.special_leave_type_name].filter(Boolean).join(' ') || null
  }
  if (request.subject_type === 'paid_leave_request' && 'leave_type_label' in summary) {
    return [summary.target_date, summary.leave_type_label].filter(Boolean).join(' ') || null
  }
  if (request.subject_type === 'shift_swap_request' && 'substitute_date' in summary) {
    return [summary.target_date, '→', summary.substitute_date].filter(Boolean).join(' ') || null
  }
  return null
}

/**
 * 一覧行にチェックボックスを出すかどうか(承認待ち一覧のsubject_summaryだけで判定)。
 * attendance_month・expense_claimはsubject_summaryに独自ステータスが含まれるためそれで
 * 判定する(ApprovalDetailPanelのisActionableと同じ閾値: submitted/in_review)。
 * paid_leave_request・special_leave_requestのsubject_summaryには独自ステータスが含まれない
 * ため、一覧が既にフィルタしているワークフロー自体のstatusで代用する。
 */
function isRowActionable(request: WorkflowRequest): boolean {
  const summary = request.subject_summary
  if (request.subject_type === 'attendance_month' && summary && 'year_month' in summary) {
    return summary.status === 'submitted'
  }
  if (request.subject_type === 'expense_claim' && summary && 'total_amount' in summary) {
    return summary.status === 'in_review'
  }
  return request.status === 'submitted'
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
  const [searchParams, setSearchParams] = useSearchParams()
  const [selectedId, setSelectedIdState] = useState<string | null>(() => searchParams.get('requestId'))

  /** 詳細モーダルの開閉をURLの`requestId`クエリパラメータにも反映させる。`/approvals?requestId=<id>`
   *  へ直接アクセスした場合に初期値としてもクエリパラメータを読む(useState初期化子側)ため、
   *  行クリック・モーダルクローズの両方でこの関数を経由させて両者を同期させる。 */
  function setSelectedId(id: string | null) {
    setSelectedIdState(id)
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        if (id) next.set('requestId', id)
        else next.delete('requestId')
        return next
      },
      { replace: true },
    )
  }
  const {
    data: selectedRequest,
    isLoading: isLoadingDetail,
    error: detailError,
  } = useWorkflowRequest(selectedId ?? '')
  const approveItem = useApproveApprovalItem()
  const returnItem = useReturnApprovalItem()

  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set())
  const [isBulkApproving, setIsBulkApproving] = useState(false)
  const [bulkApproveError, setBulkApproveError] = useState<Error | null>(null)

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
    setSelectedIds(new Set())
  }

  function clearFilters() {
    setStatus(DEFAULT_STATUS)
    setYearMonth(undefined)
    setPage(1)
    setSelectedIds(new Set())
  }

  function handlePageChange(nextPage: number) {
    setPage(nextPage)
    setSelectedIds(new Set())
  }

  function toggleRow(id: string) {
    setSelectedIds((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  /** 選択した各申請の実データ(subject)を個別に取得したうえで、一覧のsubject_summaryだけでは
   *  判別できない対象ドメインの承認API(useApproveApprovalItem)へ1件ずつ振り分ける。
   *  バックエンドに一括承認エンドポイントは存在しないため、Promise.allでのクライアント側
   *  ファンアウトで実現する。 */
  async function handleBulkApprove() {
    if (selectedIds.size === 0) return
    setBulkApproveError(null)
    setIsBulkApproving(true)
    try {
      await Promise.all(
        Array.from(selectedIds).map(async (id) => {
          const detail = await fetchWorkflowRequest(id)
          return approveItem.mutateAsync(detail)
        }),
      )
      setSelectedIds(new Set())
    } catch (e) {
      setBulkApproveError(e as Error)
    } finally {
      setIsBulkApproving(false)
    }
  }

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="承認待ち一覧の取得に失敗しました。" />

  return (
    <Card
      title="承認待ち"
      actions={
        selectedIds.size > 0 ? (
          <div className="flex items-center gap-2">
            <span className="text-sm whitespace-nowrap text-muted-foreground">{selectedIds.size}件を選択中</span>
            <Button onClick={() => void handleBulkApprove()} isLoading={isBulkApproving}>
              まとめて承認する
            </Button>
          </div>
        ) : undefined
      }
    >
      {bulkApproveError && <ErrorMessage error={bulkApproveError} />}
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
              <TableHead aria-hidden="true" />
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
              const subtitle = subjectSubtitle(request)
              const selected = selectedIds.has(request.id)
              return (
                <TableRow key={request.id} data-state={selected ? 'selected' : undefined}>
                  <TableCell>
                    {isRowActionable(request) && (
                      <Checkbox
                        checked={selected}
                        disabled={isBulkApproving}
                        onCheckedChange={() => toggleRow(request.id)}
                        aria-label={`${request.title}を選択`}
                      />
                    )}
                  </TableCell>
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
                    {subtitle && <p className="text-xs text-muted-foreground">{subtitle}</p>}
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

      {data && (
        <Pagination
          currentPage={data.meta.current_page}
          lastPage={data.meta.last_page}
          total={data.meta.total}
          onPageChange={handlePageChange}
        />
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
