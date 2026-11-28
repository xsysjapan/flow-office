import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { CalendarClock, ClipboardList, Receipt, Sunrise } from 'lucide-react'
import type { WorkflowRequest, WorkflowRequestSubjectType } from '../../api/types'
import { ApiError } from '../../api/client'
import { useAuth } from '../../auth/useAuth'
import type { FetchMyWorkflowRequestsOptions } from '../../api/workflowRequests'
import { Badge } from '../../components/Badge/Badge'
import { BulkActionBar } from '../../components/BulkActionBar/BulkActionBar'
import { BulkActionDialog, type BulkActionResult } from '../../components/BulkActionDialog/BulkActionDialog'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ClickableTableRow } from '../../components/ClickableTableRow/ClickableTableRow'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { ListFilterBar } from '../../components/ListFilterBar/ListFilterBar'
import { filterWidth } from '../../components/ListFilterBar/filterWidth'
import { Pagination } from '../../components/Pagination/Pagination'
import { PermissionDenied } from '../../components/PermissionDenied/PermissionDenied'
import { Checkbox } from '../../components/ui/checkbox'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '../../components/ui/dialog'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import { useForwardState, useSearchParamsKeepingBackState } from '../../navigation/backState'
import { useBulkCancelWorkflowRequests, useMyWorkflowRequests } from '../../hooks/useWorkflowRequests'
import {
  attendanceMonthStatusLabel,
  expenseClaimStatusLabel,
  isWorkflowRequestCancellable,
  workflowRequestStatusLabel,
} from '../../utils/statusLabels'
import { isValidPageParam } from '../../utils/searchParamValidators'

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
  const forwardState = useForwardState()
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
                navigate(option.to, { state: forwardState })
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
  { value: 'rejected', label: '却下' },
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
  { value: 'all', label: 'すべて' },
  { value: 'paid_leave_request', label: '有給' },
  { value: 'compensatory_leave_request', label: '代休' },
  { value: 'expense_claim', label: '経費精算' },
  { value: null, label: 'その他申請' },
]

/** subject_type/subjectを持たない申請種別(asset_loan等)向けの種別ラベルの上書き。
 *  request_type.codeで判定する(C: 備品貸出申請だと分かるようにする)。 */
function requestTypeBadgeLabel(request: WorkflowRequest): string {
  if (request.request_type?.code === 'asset_loan') return '備品貸出申請'
  return subjectTypeLabel(request.subject_type ?? null)
}

function subjectTypeLabel(subjectType: WorkflowRequestSubjectType): string {
  return subjectType ? SUBJECT_TYPE_LABELS[subjectType] : 'その他申請'
}

/** 一覧行の補足情報。`subject_summary`だけで組み立て、詳細取得を待たない
 *  (ApprovalsPageのsubjectSubtitleと同じ考え方)。 */
function subjectSubtitle(request: WorkflowRequest): string | null {
  if (request.request_type?.code === 'asset_loan') {
    const purpose = request.form_data.purpose
    return typeof purpose === 'string' && purpose ? `利用目的: ${purpose}` : null
  }

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
  const forwardState = useForwardState()
  const { user } = useAuth()
  const [searchParams, setSearchParams] = useSearchParamsKeepingBackState()

  const status = (searchParams.get('status') as NonNullable<FetchMyWorkflowRequestsOptions['status']> | null) ?? DEFAULT_STATUS
  const subjectTypeParam = searchParams.get('subjectType')
  const subjectType: FetchMyWorkflowRequestsOptions['subjectType'] =
    subjectTypeParam === null
      ? DEFAULT_SUBJECT_TYPE
      : subjectTypeParam === 'none'
        ? null
        : (subjectTypeParam as NonNullable<FetchMyWorkflowRequestsOptions['subjectType']>)
  const pageParam = searchParams.get('page')
  const page = isValidPageParam(pageParam) ? Number(pageParam) : 1

  const { data, error } = useMyWorkflowRequests({ status, subjectType, page })
  const bulkCancel = useBulkCancelWorkflowRequests()

  useEffect(() => {
    if (pageParam === null || isValidPageParam(pageParam)) return
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        next.delete('page')
        return next
      },
      { replace: true },
    )
  }, [pageParam, setSearchParams])

  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set())
  const [bulkReason, setBulkReason] = useState('')
  const [bulkDialogOpen, setBulkDialogOpen] = useState(false)
  /** ダイアログを開いた時点の対象。実行中に一覧が再取得されても件数・表示名が変わらないようにする。 */
  const [bulkTargets, setBulkTargets] = useState<Array<{ id: string; title: string }>>([])

  if (error instanceof ApiError && error.status === 403) return <PermissionDenied />

  const requests = data?.data ?? []
  const isRowCancellable = (request: WorkflowRequest) =>
    isWorkflowRequestCancellable(request.status) &&
    (request.subject_type !== 'attendance_month' ||
      (user?.effective_permissions?.includes('attendance.submission_revoke') ?? false))
  // 取り消せる行が1つも無いとき(一般の社員の承認済み一覧など)は、空の選択列を出さない。
  const showSelectionColumn = requests.some(isRowCancellable)
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

  function openBulkDialog() {
    setBulkReason('')
    setBulkTargets(requests.filter((request) => selectedIds.has(request.id)).map((request) => ({ id: request.id, title: request.title })))
    setBulkDialogOpen(true)
  }

  function removeFromSelection(ids: string[]) {
    setSelectedIds((prev) => {
      const next = new Set(prev)
      for (const id of ids) next.delete(id)
      return next
    })
  }

  /** 取消は元に戻せない操作(SKILL.md §2.12)のため、BulkActionDialogの確認を経由させる。
   *  選んだ申請のIDを一括取消のAPIへ1回送る(全部か無しか)。1件でも取り消せないと422で何も取り消されず、
   *  ダイアログが失敗の一覧を出して選択を残す。 */
  async function executeBulkCancel(): Promise<BulkActionResult> {
    const ids = bulkTargets.map((target) => target.id)
    await bulkCancel.mutateAsync({ ids, reason: bulkReason.trim() })
    return { succeededIds: ids, failures: [] }
  }

  return (
    <Card title="申請一覧" actions={<NewRequestDialog />}>
      <ListFilterBar className="mb-4" isFiltered={isFiltered} onClear={clearFilters}>
        <FormField label="状態" htmlFor="request-center-status" className={filterWidth.select}>
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
        <FormField label="種別" htmlFor="request-center-subject-type" className={filterWidth.select}>
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
      </ListFilterBar>
      <BulkActionBar
        selectedCount={selectedIds.size}
        onClear={() => setSelectedIds(new Set())}
        actions={[{ key: 'cancel', label: 'まとめて取消', variant: 'danger', onClick: openBulkDialog }]}
      />

      {error ? (
        <ErrorMessage error={error} fallback="申請一覧の取得に失敗しました。" />
      ) : !data ? (
        <LoadingState />
      ) : requests.length === 0 ? (
        isFiltered ? (
          <EmptyState
            title="条件に一致する申請はありません。"
            description="検索条件を変えると表示される場合があります。"
            action={
              <Button variant="secondary" onClick={clearFilters}>
                検索条件をクリア
              </Button>
            }
          />
        ) : (
          <EmptyState
            title="申請はまだありません。"
            description="「新規申請」から申請を作成できます。"
            action={<NewRequestDialog />}
          />
        )
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              {showSelectionColumn && <TableHead aria-hidden="true" />}
              <TableHead>種別</TableHead>
              <TableHead className="whitespace-normal min-w-48">タイトル</TableHead>
              <TableHead>状態</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {requests.map((request) => {
              const { label, tone } = rowStatusMeta(request)
              const subtitle = subjectSubtitle(request)
              const cancellable = isRowCancellable(request)
              const selected = selectedIds.has(request.id)
              return (
                <ClickableTableRow
                  key={request.id}
                  data-state={selected ? 'selected' : undefined}
                  onRowClick={() => navigate(`/requests/${request.id}`, { state: forwardState })}
                  rowLabel={`${request.title}の詳細を開く`}
                >
                  {showSelectionColumn && (
                    <TableCell onClick={(e) => e.stopPropagation()}>
                      {cancellable && (
                        <Checkbox
                          checked={selected}
                          onCheckedChange={() => toggleRow(request.id)}
                          aria-label={`${request.title}を選択`}
                        />
                      )}
                    </TableCell>
                  )}
                  <TableCell>
                    <Badge tone="neutral">{requestTypeBadgeLabel(request)}</Badge>
                  </TableCell>
                  <TableCell className="whitespace-normal min-w-48">
                    <Link
                      to={`/requests/${request.id}`}
                      state={forwardState}
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

      {data && !error && (
        <Pagination
          currentPage={data.meta.current_page}
          lastPage={data.meta.last_page}
          total={data.meta.total}
          onPageChange={handlePageChange}
        />
      )}

      <BulkActionDialog
        open={bulkDialogOpen}
        onOpenChange={setBulkDialogOpen}
        title={`選択した${bulkTargets.length}件の申請を取り消しますか?`}
        summary={`選択した${bulkTargets.length}件の申請を取り消します。`}
        description="この操作は元に戻せません。取り消した申請はすべて取消状態になります。"
        confirmLabel={`${bulkTargets.length}件を取り消す`}
        confirmDisabled={bulkReason.trim() === ''}
        resultTitle="取消の結果"
        resultMode="allOrNothing"
        itemLabels={Object.fromEntries(bulkTargets.map((target) => [target.id, target.title]))}
        onExecute={executeBulkCancel}
        onSucceeded={removeFromSelection}
        successMessage={(count) => `${count}件の申請を取り消しました`}
      >
        <FormField label="取消理由" htmlFor="bulk-cancel-reason" required>
          <Input
            id="bulk-cancel-reason"
            placeholder="取消理由"
            value={bulkReason}
            onChange={(e) => setBulkReason(e.target.value)}
          />
        </FormField>
      </BulkActionDialog>
    </Card>
  )
}
