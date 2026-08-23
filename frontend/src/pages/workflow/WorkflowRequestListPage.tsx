import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { CalendarClock, ClipboardList, Receipt, Sunrise } from 'lucide-react'
import type { WorkflowRequest, WorkflowRequestSubjectType } from '../../api/types'
import { ApiError } from '../../api/client'
import type { FetchMyWorkflowRequestsOptions } from '../../api/workflowRequests'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ClickableTableRow } from '../../components/ClickableTableRow/ClickableTableRow'
import { ConfirmActionDialog } from '../../components/ConfirmActionDialog/ConfirmActionDialog'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Pagination } from '../../components/Pagination/Pagination'
import { PermissionDenied } from '../../components/PermissionDenied/PermissionDenied'
import { Checkbox } from '../../components/ui/checkbox'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '../../components/ui/dialog'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import { useCancelWorkflowRequest, useMyWorkflowRequests } from '../../hooks/useWorkflowRequests'
import {
  attendanceMonthStatusLabel,
  expenseClaimStatusLabel,
  isWorkflowRequestCancellable,
  workflowRequestStatusLabel,
} from '../../utils/statusLabels'

/** 「新規申請」で選べる申請種別と、各ドメインの既存作成(または作成導線を持つ)ページへの
 *  遷移先。作成ページ自体はドメインごとに既存のものをそのまま使う。 */
const NEW_REQUEST_OPTIONS = [
  { key: 'paid-leave', label: '有給申請', description: '有給休暇を申請します。', to: '/paid-leave', icon: Sunrise },
  {
    key: 'compensatory-leave',
    label: '代休申請',
    description: '代休の取得を申請します。',
    to: '/compensatory-leave',
    icon: CalendarClock,
  },
  { key: 'expense', label: '経費精算', description: '経費の精算を申請します。', to: '/expenses/new', icon: Receipt },
  { key: 'other', label: 'その他申請', description: '上記以外の申請を行います。', to: '/requests/new', icon: ClipboardList },
] as const

/** 「新規申請」の入口。まず申請種別を選ばせ(Dialog)、選択後に各ドメインの既存
 *  作成ページへ遷移する2段階フロー(§2.11: 短時間で完了する選択操作はDialog)。 */
function NewRequestDialog() {
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <Button onClick={() => setOpen(true)}>新規申請</Button>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>申請の種類を選択してください</DialogTitle>
        </DialogHeader>
        <div className="flex flex-col gap-2">
          {NEW_REQUEST_OPTIONS.map((option) => (
            <button
              key={option.key}
              type="button"
              onClick={() => {
                setOpen(false)
                navigate(option.to)
              }}
              className="flex items-start gap-3 rounded-md border border-border p-3 text-left transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
              <option.icon className="mt-0.5 size-5 shrink-0 text-muted-foreground" aria-hidden="true" />
              <span className="flex flex-col gap-0.5">
                <span className="text-sm font-medium text-foreground">{option.label}</span>
                <span className="text-xs text-muted-foreground">{option.description}</span>
              </span>
            </button>
          ))}
        </div>
      </DialogContent>
    </Dialog>
  )
}

const DEFAULT_STATUS: NonNullable<FetchMyWorkflowRequestsOptions['status']> = 'all'
const DEFAULT_SUBJECT_TYPE: NonNullable<FetchMyWorkflowRequestsOptions['subjectType']> = 'all'

const STATUS_FILTER_OPTIONS: Array<{ value: NonNullable<FetchMyWorkflowRequestsOptions['status']>; label: string }> = [
  { value: 'all', label: 'すべて' },
  { value: 'draft', label: '下書き' },
  { value: 'submitted', label: '提出済み' },
  { value: 'approved', label: '承認済み' },
  { value: 'returned', label: '差戻し' },
  { value: 'cancelled', label: '取消' },
]

const SUBJECT_TYPE_LABELS: Record<Exclude<WorkflowRequestSubjectType, null>, string> = {
  attendance_month: '勤怠',
  expense_claim: '経費',
  paid_leave_request: '有給',
  special_leave_request: '特別休暇',
  shift_swap_request: '振替休日',
  compensatory_leave_request: '代休',
}

const SUBJECT_TYPE_FILTER_OPTIONS: Array<{ value: FetchMyWorkflowRequestsOptions['subjectType']; label: string }> = [
  { value: 'all', label: 'すべての種別' },
  { value: 'paid_leave_request', label: '有給' },
  { value: 'compensatory_leave_request', label: '代休' },
  { value: 'expense_claim', label: '経費精算' },
  { value: null, label: 'その他申請' },
]

function subjectTypeLabel(subjectType: WorkflowRequestSubjectType): string {
  return subjectType ? SUBJECT_TYPE_LABELS[subjectType] : 'その他申請'
}

/** 一覧行の補足情報。`subject_summary`だけで組み立て、詳細取得を待たない
 *  (ApprovalsPageのsubjectSubtitleと同じ考え方)。 */
function subjectSubtitle(request: WorkflowRequest): string | null {
  const summary = request.subject_summary
  if (!summary) return null

  if (request.subject_type === 'attendance_month' && 'year_month' in summary) {
    return summary.year_month
  }
  if (request.subject_type === 'expense_claim' && 'total_amount' in summary) {
    return `${summary.total_amount.toLocaleString()}円`
  }
  if (request.subject_type === 'paid_leave_request' && 'leave_type_label' in summary) {
    return [summary.target_date, summary.leave_type_label].filter(Boolean).join(' ') || null
  }
  if (request.subject_type === 'compensatory_leave_request' && 'leave_type_label' in summary) {
    return [summary.target_date, summary.leave_type_label].filter(Boolean).join(' ') || null
  }
  if (request.subject_type === 'special_leave_request' && 'special_leave_type_name' in summary) {
    return [summary.target_date, summary.special_leave_type_name].filter(Boolean).join(' ') || null
  }
  if (request.subject_type === 'shift_swap_request' && 'substitute_date' in summary) {
    return [summary.target_date, '→', summary.substitute_date].filter(Boolean).join(' ') || null
  }
  return null
}

/** 一覧行のステータスバッジ。subject種別ごとの独自ステータス(勤怠月次・経費精算)が
 *  あればそちらを優先し、無ければワークフロー自体のstatusを使う(ApprovalsPageと同じ)。 */
function rowStatusMeta(request: WorkflowRequest) {
  if (request.subject_type === 'attendance_month' && request.subject_summary && 'year_month' in request.subject_summary) {
    return attendanceMonthStatusLabel(request.subject_summary.status)
  }
  if (request.subject_type === 'expense_claim' && request.subject_summary && 'total_amount' in request.subject_summary) {
    return expenseClaimStatusLabel(request.subject_summary.status)
  }
  return workflowRequestStatusLabel(request.status)
}

/**
 * 申請センター(UC-W002手順6周辺): 有給・代休・経費精算・その他申請を横断した
 * 自分の申請一覧。status・種別で絞り込み、行クリックで各申請の詳細
 * (`WorkflowRequestDetailPage`)へ遷移する。新規作成は各ドメインの既存フォームへの
 * 入口をボタン群として提供し、フォーム自体はここに統合しない。
 *
 * 新規作成は「新規申請」ボタン1つから申請種別選択(Dialog)を経て各ドメインの既存
 * 作成ページへ遷移する2段階フローとし、フォーム自体はここに統合しない。
 *
 * 取消可能な申請(下書き/提出済み/差戻し)は複数選択し、共通の取消理由でまとめて
 * 取り消せる(オブジェクトを選択してから操作を適用するUI)。
 */
export function WorkflowRequestListPage() {
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()

  const status = (searchParams.get('status') as NonNullable<FetchMyWorkflowRequestsOptions['status']> | null) ?? DEFAULT_STATUS
  const subjectTypeParam = searchParams.get('subjectType')
  const subjectType: FetchMyWorkflowRequestsOptions['subjectType'] =
    subjectTypeParam === null
      ? DEFAULT_SUBJECT_TYPE
      : subjectTypeParam === 'none'
        ? null
        : (subjectTypeParam as NonNullable<FetchMyWorkflowRequestsOptions['subjectType']>)
  const pageParam = Number(searchParams.get('page'))
  const page = Number.isInteger(pageParam) && pageParam > 0 ? pageParam : 1

  const { data, isLoading, error } = useMyWorkflowRequests({ status, subjectType, page })
  const cancelRequest = useCancelWorkflowRequest()

  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set())
  const [bulkReason, setBulkReason] = useState('')
  const [isBulkCancelling, setIsBulkCancelling] = useState(false)
  const [bulkError, setBulkError] = useState<Error | null>(null)

  if (isLoading) return <LoadingState />
  if (error instanceof ApiError && error.status === 403) return <PermissionDenied />
  if (error) return <ErrorMessage error={error} fallback="申請一覧の取得に失敗しました。" />

  const requests = data?.data ?? []
  const isFiltered = status !== DEFAULT_STATUS || subjectType !== DEFAULT_SUBJECT_TYPE

  /** 指定したキーだけをURLクエリパラメータへ反映する(他のキーはそのまま維持)。
   *  値`null`はパラメータの削除、`'none'`は「その他申請」(subject_typeがnull)を表す。 */
  function updateParams(patch: Record<string, string | null>) {
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        for (const [key, value] of Object.entries(patch)) {
          if (value === null) next.delete(key)
          else next.set(key, value)
        }
        return next
      },
      { replace: true },
    )
  }

  function handleFilterChange(next: { status?: NonNullable<FetchMyWorkflowRequestsOptions['status']>; subjectType?: string }) {
    const patch: Record<string, string | null> = { page: null }
    if (next.status !== undefined) patch.status = next.status === DEFAULT_STATUS ? null : next.status
    if (next.subjectType !== undefined) patch.subjectType = next.subjectType === 'all' ? null : next.subjectType
    updateParams(patch)
    setSelectedIds(new Set())
  }

  function clearFilters() {
    updateParams({ status: null, subjectType: null, page: null })
    setSelectedIds(new Set())
  }

  function handlePageChange(nextPage: number) {
    updateParams({ page: String(nextPage) })
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

  /** 取消は元に戻せない操作(SKILL.md §2.12)のため、確認ダイアログ(ConfirmActionDialog)を
   *  経由させる。理由未入力の場合はダイアログを開いたまま留める(Promiseをthrowして
   *  ConfirmActionDialog側のcatchで「開いたまま」を維持する)。 */
  async function handleBulkCancel() {
    if (selectedIds.size === 0) return
    if (!bulkReason) {
      const emptyReasonError = new Error('取消理由を入力してください。')
      setBulkError(emptyReasonError)
      throw emptyReasonError
    }
    setBulkError(null)
    setIsBulkCancelling(true)
    try {
      await Promise.all(
        Array.from(selectedIds).map((id) => cancelRequest.mutateAsync({ id, reason: bulkReason })),
      )
      setSelectedIds(new Set())
      setBulkReason('')
    } catch (e) {
      setBulkError(e as Error)
      throw e
    } finally {
      setIsBulkCancelling(false)
    }
  }

  return (
    <Card
      title="申請一覧"
      actions={
        selectedIds.size > 0 ? (
          <div className="flex items-center gap-2">
            <span className="text-sm whitespace-nowrap text-muted-foreground">{selectedIds.size}件を選択中</span>
            <ConfirmActionDialog
              triggerLabel="まとめて取消"
              triggerVariant="danger"
              title={`選択した${selectedIds.size}件の申請を取り消しますか?`}
              description="この操作は元に戻せません。選択した申請はすべて取消状態になります。"
              confirmLabel="まとめて取り消す"
              isPending={isBulkCancelling}
              error={bulkError}
              onConfirm={handleBulkCancel}
              onOpenChange={(open) => {
                if (open) {
                  setBulkReason('')
                  setBulkError(null)
                }
              }}
            >
              <FormField label="取消理由" htmlFor="bulk-cancel-reason" required>
                <Input
                  id="bulk-cancel-reason"
                  placeholder="取消理由"
                  value={bulkReason}
                  onChange={(e) => setBulkReason(e.target.value)}
                />
              </FormField>
            </ConfirmActionDialog>
          </div>
        ) : (
          <NewRequestDialog />
        )
      }
    >
      <div className="mb-4 flex flex-wrap items-end gap-4">
        <div className="w-40">
          <FormField label="状態" htmlFor="request-center-status">
            <NativeSelect
              id="request-center-status"
              value={status}
              onChange={(event) =>
                handleFilterChange({ status: event.target.value as NonNullable<FetchMyWorkflowRequestsOptions['status']> })
              }
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
          <FormField label="種別" htmlFor="request-center-subject-type">
            <NativeSelect
              id="request-center-subject-type"
              value={subjectType === null ? 'none' : (subjectType ?? 'all')}
              onChange={(event) => handleFilterChange({ subjectType: event.target.value })}
            >
              {SUBJECT_TYPE_FILTER_OPTIONS.map((option) => (
                <option key={option.value ?? 'none'} value={option.value === null ? 'none' : option.value}>
                  {option.label}
                </option>
              ))}
            </NativeSelect>
          </FormField>
        </div>
        {isFiltered && (
          <Button variant="secondary" onClick={clearFilters}>
            フィルターをクリア
          </Button>
        )}
      </div>

      {requests.length === 0 ? (
        isFiltered ? (
          <EmptyState
            title="条件に一致する申請はありません。"
            description="状態や種別の条件を変えると表示される場合があります。"
            action={
              <Button variant="secondary" size="sm" onClick={clearFilters}>
                フィルターをクリア
              </Button>
            }
          />
        ) : (
          <EmptyState
            title="申請はまだありません。"
            description="「新規申請」から有給・代休・経費精算・その他申請を行えます。"
          />
        )
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead aria-hidden="true" />
              <TableHead>種別</TableHead>
              <TableHead>タイトル</TableHead>
              <TableHead>状態</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {requests.map((request) => {
              const { label, tone } = rowStatusMeta(request)
              const subtitle = subjectSubtitle(request)
              const cancellable = isWorkflowRequestCancellable(request.status)
              const selected = selectedIds.has(request.id)
              return (
                <ClickableTableRow
                  key={request.id}
                  data-state={selected ? 'selected' : undefined}
                  onRowClick={() => navigate(`/requests/${request.id}`)}
                  rowLabel={`${request.title}の詳細を開く`}
                >
                  <TableCell onClick={(e) => e.stopPropagation()}>
                    {cancellable && (
                      <Checkbox
                        checked={selected}
                        onCheckedChange={() => toggleRow(request.id)}
                        aria-label={`${request.title}を選択`}
                      />
                    )}
                  </TableCell>
                  <TableCell>
                    <Badge tone="neutral">{subjectTypeLabel(request.subject_type ?? null)}</Badge>
                  </TableCell>
                  <TableCell>
                    <Link
                      to={`/requests/${request.id}`}
                      className="font-medium text-foreground hover:text-primary hover:underline"
                      onClick={(e) => e.stopPropagation()}
                    >
                      {request.title}
                    </Link>
                    {subtitle && <p className="text-xs text-muted-foreground">{subtitle}</p>}
                  </TableCell>
                  <TableCell>
                    <Badge tone={tone}>{label}</Badge>
                  </TableCell>
                </ClickableTableRow>
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
    </Card>
  )
}
