import { useEffect, useState } from 'react'
import { ApprovalDetailPanel } from '../../components/ApprovalDetailPanel/ApprovalDetailPanel'
import { Badge } from '../../components/Badge/Badge'
import { BulkActionBar } from '../../components/BulkActionBar/BulkActionBar'
import { BulkActionDialog, type BulkActionResult } from '../../components/BulkActionDialog/BulkActionDialog'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ClickableTableRow } from '../../components/ClickableTableRow/ClickableTableRow'
import { ConfirmActionDialog } from '../../components/ConfirmActionDialog/ConfirmActionDialog'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { ListFilterBar } from '../../components/ListFilterBar/ListFilterBar'
import { filterWidth } from '../../components/ListFilterBar/filterWidth'
import { ListQueueTabs } from '../../components/ListQueueTabs/ListQueueTabs'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Pagination } from '../../components/Pagination/Pagination'
import { Checkbox } from '../../components/ui/checkbox'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '../../components/ui/dialog'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import { YearMonthPicker } from '../../components/YearMonthPicker/YearMonthPicker'
import type { WorkflowRequest, WorkflowRequestSubjectType } from '../../api/types'
import type { FetchWorkflowRequestsToApproveOptions } from '../../api/workflowRequests'
import { useSearchParamsKeepingBackState } from '../../navigation/backState'
import { notify } from '../../lib/notify'
import { useApproveApprovalItem, useReturnApprovalItem } from '../../hooks/useApprovals'
import { useBulkApproveWorkflowRequests, useWorkflowRequest, useWorkflowRequestsToApprove } from '../../hooks/useWorkflowRequests'
import { isValidPageParam } from '../../utils/searchParamValidators'
import { attendanceMonthStatusLabel, expenseClaimStatusLabel, workflowRequestStatusLabel } from '../../utils/statusLabels'

const DEFAULT_STATUS: NonNullable<FetchWorkflowRequestsToApproveOptions['status']> = 'submitted'

type ApprovalStatus = NonNullable<FetchWorkflowRequestsToApproveOptions['status']>

/** 状態タブ。並び順はこの順に固定する。emptyTitleはフィルターが無いときの空状態の文言。 */
const STATUS_TABS: Array<{ value: 'submitted' | 'approved' | 'returned' | 'cancelled' | 'all'; label: string; emptyTitle: string }> = [
  { value: 'submitted', label: '承認待ち', emptyTitle: '承認待ちの申請はありません。' },
  { value: 'approved', label: '承認済み', emptyTitle: '承認済みの申請はありません。' },
  { value: 'returned', label: '差戻し済み', emptyTitle: '差戻し済みの申請はありません。' },
  { value: 'cancelled', label: '取消', emptyTitle: '取消された申請はありません。' },
  { value: 'all', label: 'すべて', emptyTitle: '承認対象の申請はありません。' },
]

const SUBJECT_TYPE_LABELS: Record<Exclude<WorkflowRequestSubjectType, null>, string> = {
  attendance_month: '勤怠',
  expense_claim: '経費',
  paid_leave_request: '有給',
  special_leave_request: '特別休暇',
  shift_swap_request: '振替休日',
  compensatory_leave_request: '代休',
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
  if (request.subject_type === 'compensatory_leave_request' && 'leave_type_label' in summary) {
    return [summary.target_date, summary.leave_type_label].filter(Boolean).join(' ') || null
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
  const [searchParams, setSearchParams] = useSearchParamsKeepingBackState()

  /** フィルター・ページ・詳細パネル対象IDはすべてURLに載せる(SKILL.md §2.10)。ローカルstateを
   *  正にせず、URLを唯一の正として都度読み出すことで、Browser Back・リロード・URL共有で状態が
   *  壊れないようにする。 */
  const status = (searchParams.get('status') as ApprovalStatus | null) ?? DEFAULT_STATUS
  const yearMonthParam = searchParams.get('yearMonth')
  const isInvalidYearMonth = yearMonthParam !== null && !/^\d{4}-(0[1-9]|1[0-2])$/.test(yearMonthParam)
  // 不正な年月は絞り込みなしとして扱い、URLからもキーを消す(表示とURLを一致させる)
  const yearMonth = yearMonthParam !== null && !isInvalidYearMonth ? yearMonthParam : undefined
  const pageParam = searchParams.get('page')
  const page = isValidPageParam(pageParam) ? Number(pageParam) : 1
  const selectedId = searchParams.get('requestId')

  const { data, error } = useWorkflowRequestsToApprove({ status, yearMonth, page })

  /** 指定したキーだけをURLクエリパラメータへ反映する(他のキーはそのまま維持)。値`null`は
   *  パラメータの削除を意味する。 */
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

  useEffect(() => {
    if (!isInvalidYearMonth) return
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        next.delete('yearMonth')
        return next
      },
      { replace: true },
    )
  }, [isInvalidYearMonth, setSearchParams])

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

  function setSelectedId(id: string | null) {
    updateParams({ requestId: id })
  }
  const {
    data: selectedRequest,
    isLoading: isLoadingDetail,
    error: detailError,
  } = useWorkflowRequest(selectedId ?? '')
  const approveItem = useApproveApprovalItem()
  const returnItem = useReturnApprovalItem()
  const bulkApprove = useBulkApproveWorkflowRequests()

  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set())
  const [bulkDialogOpen, setBulkDialogOpen] = useState(false)
  /** ダイアログを開いた時点の対象。実行中に一覧が再取得されても件数・表示名が変わらないようにする。 */
  const [bulkTargets, setBulkTargets] = useState<Array<{ id: string; title: string }>>([])
  const [approveConfirmOpen, setApproveConfirmOpen] = useState(false)

  const requests = data?.data ?? []
  // 操作できる行が1つも無いときは、空の選択列を出さない。
  const showSelectionColumn = requests.some(isRowActionable)
  // タブ(status)は含めない。フィルターバーの条件だけで判定する
  const isFiltered = Boolean(yearMonth)
  const currentTab = STATUS_TABS.find((tab) => tab.value === status) ?? STATUS_TABS[0]

  function closePanel() {
    setSelectedId(null)
    setApproveConfirmOpen(false)
    approveItem.reset()
    returnItem.reset()
  }

  function changeStatus(next: string) {
    updateParams({ status: next === DEFAULT_STATUS ? null : next, page: null })
    setSelectedIds(new Set())
  }

  function changeYearMonth(value: string | undefined) {
    updateParams({ yearMonth: value || null, page: null })
    setSelectedIds(new Set())
  }

  /** 年月と page を消す。状態タブ・詳細パネルの対象ID(requestId)は残す。 */
  function clearFilters() {
    updateParams({ yearMonth: null, page: null })
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

  /** 選んだワークフローの申請のIDをまとめて一括承認のAPIへ1回送る(全部か無しか)。業務側(月次勤怠・経費・休暇等)は
   *  バックエンドが承認の連鎖で更新する。1件でも処理できないと422で何も承認されず、ダイアログが失敗の一覧を出す。 */
  async function executeBulkApprove(): Promise<BulkActionResult> {
    const ids = bulkTargets.map((target) => target.id)
    await bulkApprove.mutateAsync(ids)
    return { succeededIds: ids, failures: [] }
  }

  // 初回読み込みのみ画面全体をLoadingにする(以降はplaceholderDataで前の結果を残す)。取得エラーは表の場所に出す
  if (!data && !error) return <LoadingState />

  return (
    <Card title="承認一覧">
      <ListQueueTabs
        aria-label="状態"
        className="mb-4"
        value={currentTab.value}
        onChange={changeStatus}
        tabs={STATUS_TABS.map((tab) => ({ value: tab.value, label: tab.label, count: data?.meta.status_counts[tab.value] }))}
      />
      <ListFilterBar className="mb-4" isFiltered={isFiltered} onClear={clearFilters}>
        <FormField label="年月" htmlFor="approvals-year-month" className={filterWidth.yearMonth}>
          <YearMonthPicker id="approvals-year-month" value={yearMonth} onChange={(value) => changeYearMonth(value ?? undefined)} />
        </FormField>
      </ListFilterBar>
      <BulkActionBar
        selectedCount={selectedIds.size}
        onClear={() => setSelectedIds(new Set())}
        actions={[{ key: 'approve', label: 'まとめて承認する', onClick: openBulkDialog }]}
      />

      {error ? (
        <ErrorMessage error={error} fallback="承認一覧の取得に失敗しました。" />
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
          <EmptyState title={currentTab.emptyTitle} />
        )
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              {showSelectionColumn && <TableHead aria-hidden="true" />}
              <TableHead>種別</TableHead>
              <TableHead className="whitespace-normal min-w-48">タイトル</TableHead>
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
                <ClickableTableRow
                  key={request.id}
                  data-state={selected ? 'selected' : undefined}
                  onRowClick={() => setSelectedId(request.id)}
                  rowLabel={`${request.title}の詳細を開く`}
                >
                  {showSelectionColumn && (
                    <TableCell onClick={(e) => e.stopPropagation()}>
                      {isRowActionable(request) && (
                        <Checkbox
                          checked={selected}
                          onCheckedChange={() => toggleRow(request.id)}
                          aria-label={`${request.title}を選択`}
                        />
                      )}
                    </TableCell>
                  )}
                  <TableCell>
                    <Badge tone="neutral">{subjectTypeLabel(request.subject_type ?? null)}</Badge>
                  </TableCell>
                  <TableCell className="whitespace-normal min-w-48">
                    {/* 行全体をクリック可能にした上で、タイトル自体もbuttonのままにしてキーボード
                        (Tab→Enter/Space)から詳細を開けるようにする(SKILL.md §2.2)。 */}
                    <button
                      type="button"
                      className="font-medium text-foreground hover:text-primary hover:underline"
                      onClick={(e) => {
                        e.stopPropagation()
                        setSelectedId(request.id)
                      }}
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

      <Dialog open={selectedId !== null} onOpenChange={(open) => !open && closePanel()}>
        <DialogContent size="large" className="sm:max-w-2xl">
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
              actionError={returnItem.error}
              onApprove={() => {
                approveItem.reset()
                setApproveConfirmOpen(true)
              }}
              onReturn={(comment) =>
                returnItem.mutate({ request: selectedRequest, comment }, { onSuccess: () => notify.success('申請を差し戻しました') })
              }
            />
          ) : null}
        </DialogContent>
      </Dialog>

      <ConfirmActionDialog
        open={approveConfirmOpen}
        onOpenChange={setApproveConfirmOpen}
        title="この申請を承認しますか?"
        description="承認すると取り消せません。申請者に承認の通知が届きます。"
        confirmLabel="承認する"
        confirmVariant="primary"
        isPending={approveItem.isPending}
        error={approveItem.error}
        onConfirm={async () => {
          if (!selectedRequest) return
          await approveItem.mutateAsync(selectedRequest)
          notify.success('申請を承認しました')
        }}
      />

      <BulkActionDialog
        open={bulkDialogOpen}
        onOpenChange={setBulkDialogOpen}
        title={`選択した${bulkTargets.length}件を承認しますか?`}
        summary={`選択した${bulkTargets.length}件の申請を承認します。`}
        description="承認すると取り消せません。申請者に承認の通知が届きます。"
        confirmLabel={`${bulkTargets.length}件を承認する`}
        resultTitle="承認の結果"
        resultMode="allOrNothing"
        itemLabels={Object.fromEntries(bulkTargets.map((target) => [target.id, target.title]))}
        onExecute={executeBulkApprove}
        onSucceeded={removeFromSelection}
        successMessage={(count) => `${count}件の申請を承認しました`}
      />
    </Card>
  )
}
