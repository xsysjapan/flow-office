import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useForwardState, useSearchParamsKeepingBackState } from '../../navigation/backState'
import { ApiError } from '../../api/client'
import { useAuth } from '../../auth/useAuth'
import { Badge } from '../../components/Badge/Badge'
import { BulkActionBar } from '../../components/BulkActionBar/BulkActionBar'
import { BulkActionDialog, type BulkActionFailure, type BulkActionResult } from '../../components/BulkActionDialog/BulkActionDialog'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ClickableTableRow } from '../../components/ClickableTableRow/ClickableTableRow'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Pagination } from '../../components/Pagination/Pagination'
import { PermissionDenied } from '../../components/PermissionDenied/PermissionDenied'
import { FormField } from '../../components/FormField/FormField'
import { ListFilterBar } from '../../components/ListFilterBar/ListFilterBar'
import { filterWidth } from '../../components/ListFilterBar/filterWidth'
import { ListQueueTabs } from '../../components/ListQueueTabs/ListQueueTabs'
import { TextFilterInput } from '../../components/TextFilterInput/TextFilterInput'
import { Checkbox } from '../../components/ui/checkbox'
import { NativeSelect } from '../../components/ui/native-select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import type { BackOfficeTask, BackOfficeTaskAssigneeScope, BackOfficeTaskStatusFilter } from '../../api/types'
import {
  useAssignBackOfficeTask,
  useBackOfficeTasks,
  useBulkCompleteBackOfficeTasks,
} from '../../hooks/useBackOfficeTasks'
import { BACKOFFICE_TASK_STATUSES } from '../../utils/backOfficeTaskStatuses'
import { isOneOf, isValidPageParam } from '../../utils/searchParamValidators'
import { backOfficeTaskStatusLabel } from '../../utils/statusLabels'

interface BackOfficeTaskTableProps {
  tasks: BackOfficeTask[]
  selectedIds?: Set<string>
  onToggleRow?: (id: string) => void
  onToggleAll?: (ids: string[]) => void
  isRowSelectable?: (task: BackOfficeTask) => boolean
}

/** selectedIds/onToggleRowを渡した場合のみ、行選択用のチェックボックス列を表示する。 */
function BackOfficeTaskTable({ tasks, selectedIds, onToggleRow, onToggleAll, isRowSelectable }: BackOfficeTaskTableProps) {
  const navigate = useNavigate()
  const forwardState = useForwardState()
  const selectable = Boolean(selectedIds && onToggleRow)
  const selectableTasks = tasks.filter((task) => isRowSelectable?.(task) ?? true)
  const selectedOnPage = selectableTasks.filter((task) => selectedIds?.has(task.id)).length
  const allSelected = selectableTasks.length > 0 && selectedOnPage === selectableTasks.length
  const partiallySelected = selectedOnPage > 0 && !allSelected

  return (
    <Table>
      <TableHeader>
        <TableRow>
          {selectable && (
            <TableHead>
              <Checkbox
                checked={allSelected ? true : partiallySelected ? 'indeterminate' : false}
                disabled={selectableTasks.length === 0}
                onCheckedChange={() => onToggleAll?.(selectableTasks.map((task) => task.id))}
                aria-label="このページのタスクをすべて選択"
              />
            </TableHead>
          )}
          <TableHead className="whitespace-normal min-w-48">タイトル</TableHead>
          <TableHead>種別</TableHead>
          <TableHead>担当者</TableHead>
          <TableHead>期限</TableHead>
          <TableHead>ステータス</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {tasks.map((task) => {
          const { label, tone } = backOfficeTaskStatusLabel(task.status)
          const selected = selectedIds?.has(task.id) ?? false
          const rowSelectable = isRowSelectable?.(task) ?? true
          return (
            <ClickableTableRow
              key={task.id}
              data-state={selected ? 'selected' : undefined}
              onRowClick={() => navigate(`/backoffice-tasks/${task.id}`, { state: forwardState })}
              rowLabel={`${task.title}の詳細を開く`}
            >
              {selectable && (
                <TableCell onClick={(e) => e.stopPropagation()}>
                  <Checkbox
                    checked={selected}
                    disabled={!rowSelectable}
                    onCheckedChange={() => onToggleRow?.(task.id)}
                    aria-label={`${task.title}を選択`}
                  />
                </TableCell>
              )}
              <TableCell className="whitespace-normal min-w-48">
                <Link
                  to={`/backoffice-tasks/${task.id}`}
                  state={forwardState}
                  className="font-medium text-foreground hover:text-primary hover:underline"
                  onClick={(e) => e.stopPropagation()}
                >
                  {task.title}
                </Link>
              </TableCell>
              <TableCell className="text-muted-foreground">{task.task_type}</TableCell>
              <TableCell className="text-muted-foreground">{task.assignee?.name ?? '-'}</TableCell>
              <TableCell className="text-muted-foreground">{task.due_on ? `期限: ${task.due_on}` : '-'}</TableCell>
              <TableCell>
                <Badge tone={tone}>{label}</Badge>
              </TableCell>
            </ClickableTableRow>
          )
        })}
      </TableBody>
    </Table>
  )
}

/** 担当者タブ。値はAPIの`assignee`と同じ。並び順はこの順に固定する。 */
const ASSIGNEE_TABS: { scope: BackOfficeTaskAssigneeScope; label: string; emptyTitle: string }[] = [
  { scope: 'unassigned', label: '未割り当て', emptyTitle: '未割り当てのタスクはありません。' },
  { scope: 'me', label: '自分のタスク', emptyTitle: '担当中のタスクはありません。' },
  { scope: 'others', label: '他の担当者', emptyTitle: '他の担当者に割り当てられたタスクはありません。' },
  { scope: 'all', label: 'すべて', emptyTitle: 'タスクはありません。' },
]
const ASSIGNEE_SCOPES = ASSIGNEE_TABS.map((tab) => tab.scope)
const DEFAULT_ASSIGNEE: BackOfficeTaskAssigneeScope = 'unassigned'

/** 「未完了」(open)・「すべて」(all)・個別ステータスの順。 */
const STATUS_FILTERS: BackOfficeTaskStatusFilter[] = ['open', 'all', ...BACKOFFICE_TASK_STATUSES]
const DEFAULT_STATUS: BackOfficeTaskStatusFilter = 'open'

function statusFilterLabel(status: BackOfficeTaskStatusFilter): string {
  if (status === 'open') return '未完了'
  if (status === 'all') return 'すべて'
  return backOfficeTaskStatusLabel(status).label
}

/** 完了・取消のタスクは一括操作の対象にしない。 */
function isSelectableTask(task: BackOfficeTask): boolean {
  return !['completed', 'cancelled'].includes(task.status)
}

/**
 * UC-11: バックオフィス処理タスクの汎用検索画面。担当者タブ(未割り当て・自分のタスク・他の担当者・
 * すべて。件数付き)で担当者区分を選び、キーワードとステータス(既定は未完了)で絞り込んで一覧する。
 * 画面の条件(q・assignee・status・page)はURLに載せ、詳細→戻るで復元する。
 * 行を選ぶと一括操作バーが出る。担当者の設定は完了・取消以外のタスクなら担当者の状態に関係なく可能、
 * 一括完了は自分のタスクだけ可能で、選んだタスクの性質で押せるかを決める(タブ名では決めない)。
 */
export function BackOfficeTaskListPage() {
  const { user } = useAuth()
  /** 検索条件・タブ・ページはURLに載せ、一覧→詳細→一覧と戻っても状態が壊れないようにする(SKILL.md §2.10)。
   *  戻り先の情報(backTo)は引き継ぐため、更新にはuseSearchParamsKeepingBackStateを使う。 */
  const [searchParams, setSearchParams] = useSearchParamsKeepingBackState()
  const search = searchParams.get('q') ?? ''

  const assigneeParam = searchParams.get('assignee')
  const statusParam = searchParams.get('status')
  const pageParam = searchParams.get('page')
  const isInvalidAssignee = assigneeParam !== null && !isOneOf(assigneeParam, ASSIGNEE_SCOPES)
  const isInvalidStatus = statusParam !== null && !isOneOf(statusParam, STATUS_FILTERS)
  const isInvalidPage = pageParam !== null && !isValidPageParam(pageParam)
  const assignee: BackOfficeTaskAssigneeScope =
    assigneeParam !== null && isOneOf(assigneeParam, ASSIGNEE_SCOPES) ? assigneeParam : DEFAULT_ASSIGNEE
  const status: BackOfficeTaskStatusFilter =
    statusParam !== null && isOneOf(statusParam, STATUS_FILTERS) ? statusParam : DEFAULT_STATUS
  const page = pageParam !== null && isValidPageParam(pageParam) ? Number(pageParam) : 1
  const isFiltered = search !== '' || status !== DEFAULT_STATUS

  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set())
  /** 一括操作ダイアログを開いた時点の選択(実行中・結果表示中に選択が変わっても件数がぶれないようにする) */
  const [dialogTasks, setDialogTasks] = useState<BackOfficeTask[]>([])
  const [assignDialogOpen, setAssignDialogOpen] = useState(false)
  const [completeDialogOpen, setCompleteDialogOpen] = useState(false)
  const [bulkAssignee, setBulkAssignee] = useState<{ id: string; name: string } | undefined>(undefined)

  const tasksQuery = useBackOfficeTasks({ search: search || undefined, assignee, status, page })
  const assignTask = useAssignBackOfficeTask()
  const bulkComplete = useBulkCompleteBackOfficeTasks()

  // 不正なassignee・status・pageは既定扱いにし、URLからもそのキーを消す(不正なときだけ動かしループさせない)
  useEffect(() => {
    if (!isInvalidAssignee && !isInvalidStatus && !isInvalidPage) return
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        if (isInvalidAssignee) next.delete('assignee')
        if (isInvalidStatus) next.delete('status')
        if (isInvalidPage) next.delete('page')
        return next
      },
      { replace: true },
    )
  }, [isInvalidAssignee, isInvalidStatus, isInvalidPage, setSearchParams])

  const result = tasksQuery.data
  if (tasksQuery.error instanceof ApiError && tasksQuery.error.status === 403) return <PermissionDenied />

  // 初回(データ無し)のみ表の場所をLoadingにする。条件切替中はplaceholderDataで前の結果を残し、
  // 取得エラー時もタブ・フィルターバーは残す(不正な条件を直せるように)
  const tasks = result?.data ?? []
  const counts = result?.meta.assignee_counts
  const selectedCount = selectedIds.size
  const selectedTasks = tasks.filter((task) => selectedIds.has(task.id))

  // 完了は自分のタスクだけ(自分の判定は担当者IDの一致)。担当者の変更は完了・取消以外のタスクなら担当者の状態に関係なく可能
  const isMine = (task: BackOfficeTask) => user !== null && task.assignee?.id === user.id
  const allMine = selectedTasks.every(isMine)
  const completeReason = allMine ? undefined : '自分のタスクのみ完了にできます'

  /** 担当者を変更するダイアログの対象。変更先がすでに担当者の行は「変更不要」として実行対象から外す。 */
  const assignTargets = dialogTasks.filter((task) => task.assignee?.id !== bulkAssignee?.id || !bulkAssignee)
  const unchangedCount = bulkAssignee ? dialogTasks.length - assignTargets.length : 0
  const unassignedCount = dialogTasks.filter((task) => !task.assignee).length
  const assignedCount = dialogTasks.length - unassignedCount

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

  function clearSelection() {
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

  function toggleAll(ids: string[]) {
    setSelectedIds((prev) => {
      const next = new Set(prev)
      const shouldSelect = ids.some((id) => !next.has(id))
      ids.forEach((id) => (shouldSelect ? next.add(id) : next.delete(id)))
      return next
    })
  }

  function changeAssignee(next: BackOfficeTaskAssigneeScope) {
    updateParams({ assignee: next === DEFAULT_ASSIGNEE ? null : next, page: null })
    clearSelection()
  }

  function changeStatus(next: BackOfficeTaskStatusFilter) {
    updateParams({ status: next === DEFAULT_STATUS ? null : next, page: null })
    clearSelection()
  }

  function changePage(next: number) {
    updateParams({ page: next > 1 ? String(next) : null })
    clearSelection()
  }

  function changeSearch(next: string | null) {
    updateParams({ q: next, page: null })
    clearSelection()
  }

  /** 検索語とステータスを既定に戻す。担当者タブは維持する。 */
  function clearConditions() {
    updateParams({ q: null, status: null, page: null })
    clearSelection()
  }

  function removeFromSelection(ids: string[]) {
    setSelectedIds((prev) => {
      const next = new Set(prev)
      ids.forEach((id) => next.delete(id))
      return next
    })
  }

  function openAssignDialog() {
    setDialogTasks(selectedTasks)
    setBulkAssignee(undefined)
    setAssignDialogOpen(true)
  }

  function openCompleteDialog() {
    setDialogTasks(selectedTasks)
    setCompleteDialogOpen(true)
  }

  async function executeBulkAssign(): Promise<BulkActionResult> {
    if (!bulkAssignee) return { succeededIds: [], failures: [] }
    const results = await Promise.allSettled(
      assignTargets.map((task) => assignTask.mutateAsync({ id: task.id, assignedUserId: bulkAssignee.id })),
    )
    const succeededIds: string[] = []
    const failures: BulkActionFailure[] = []
    results.forEach((result, index) => {
      const task = assignTargets[index]
      if (result.status === 'fulfilled') {
        succeededIds.push(task.id)
      } else {
        failures.push({
          id: task.id,
          label: task.title,
          message: result.reason instanceof ApiError ? result.reason.message : '担当者を変更できませんでした。',
        })
      }
    })
    return { succeededIds, failures }
  }

  async function executeBulkComplete(): Promise<BulkActionResult> {
    // 全体成功/全体失敗のAPI。失敗はそのまま例外にして、ダイアログ内のErrorMessageに出す
    const ids = dialogTasks.map((task) => task.id)
    await bulkComplete.mutateAsync(ids)
    return { succeededIds: ids, failures: [] }
  }

  const currentTab = ASSIGNEE_TABS.find((tab) => tab.scope === assignee) ?? ASSIGNEE_TABS[0]

  return (
    <div className="flex flex-col gap-6">
      <Card>
        <div className="flex flex-col gap-4">
          <ListQueueTabs
            aria-label="タスクの担当者区分"
            tabs={ASSIGNEE_TABS.map((tab) => ({
              value: tab.scope,
              label: tab.label,
              count: counts?.[tab.scope],
            }))}
            value={assignee}
            onChange={(value) => {
              if (isOneOf(value, ASSIGNEE_SCOPES)) changeAssignee(value)
            }}
          />

          <ListFilterBar isFiltered={isFiltered} onClear={clearConditions}>
            <TextFilterInput
              label="キーワード"
              placeholder="タイトル・種別・担当部署・担当者名"
              maxLength={100}
              className={filterWidth.keyword}
              value={search || null}
              onChange={changeSearch}
            />
            <FormField label="ステータス" htmlFor="task-status-filter" className={filterWidth.select}>
              <NativeSelect
                id="task-status-filter"
                value={status}
                onChange={(event) => {
                  const next = event.target.value
                  if (isOneOf(next, STATUS_FILTERS)) changeStatus(next)
                }}
              >
                {STATUS_FILTERS.map((option) => (
                  <option key={option} value={option}>
                    {statusFilterLabel(option)}
                  </option>
                ))}
              </NativeSelect>
            </FormField>
          </ListFilterBar>

          <BulkActionBar
            selectedCount={selectedCount}
            onClear={clearSelection}
            actions={[
              { key: 'assign', label: '担当者を変更', onClick: openAssignDialog },
              {
                key: 'complete',
                label: '完了にする',
                onClick: openCompleteDialog,
                disabled: completeReason !== undefined,
                disabledReason: completeReason,
              },
            ]}
          />

          {tasksQuery.error && !result ? (
            <ErrorMessage error={tasksQuery.error} fallback="タスクの取得に失敗しました。" />
          ) : !result ? (
            <LoadingState />
          ) : tasks.length === 0 ? (
            isFiltered ? (
              <EmptyState
                title="条件に一致するタスクがありません。"
                description="検索条件を変えると表示される場合があります。"
                action={
                  <Button variant="secondary" size="sm" onClick={clearConditions}>
                    検索条件をクリア
                  </Button>
                }
              />
            ) : (
              <EmptyState title={currentTab.emptyTitle} />
            )
          ) : (
            <>
              <BackOfficeTaskTable
                tasks={tasks}
                selectedIds={selectedIds}
                onToggleRow={toggleRow}
                onToggleAll={toggleAll}
                isRowSelectable={isSelectableTask}
              />
              <Pagination
                currentPage={result.meta.current_page}
                lastPage={result.meta.last_page}
                total={result.meta.total}
                onPageChange={changePage}
              />
            </>
          )}
        </div>
      </Card>

      <BulkActionDialog
        open={assignDialogOpen}
        onOpenChange={setAssignDialogOpen}
        title="担当者を変更しますか?"
        summary={`選択した${dialogTasks.length}件(未割り当て${unassignedCount}件・割り当て済み${assignedCount}件${
          bulkAssignee ? `。変更不要${unchangedCount}件` : ''
        })`}
        description="ステータスは変わりません。未着手のタスクは確認中になります(申請種別の遷移ルールで許可されている場合)。新しい担当者と前の担当者に通知されます。"
        confirmLabel={`${assignTargets.length}件の担当者を変更`}
        confirmDisabled={!bulkAssignee || assignTargets.length === 0}
        resultTitle="担当者の変更結果"
        onExecute={executeBulkAssign}
        onSucceeded={removeFromSelection}
        successMessage={(count) => `${count}件のタスクの担当者を${bulkAssignee?.name ?? ''}さんに変更しました`}
      >
        <FormField label="変更先の担当者" htmlFor="bulk-assignee">
          <UserPicker
            id="bulk-assignee"
            value={bulkAssignee?.id}
            onChange={(id, picked) => setBulkAssignee(id && picked ? { id, name: picked.name } : undefined)}
            placeholder="担当者を選択"
          />
        </FormField>
      </BulkActionDialog>

      <BulkActionDialog
        open={completeDialogOpen}
        onOpenChange={setCompleteDialogOpen}
        title={`選択した${dialogTasks.length}件を完了にしますか?`}
        summary={`選択した${dialogTasks.length}件`}
        description="完了すると担当者を変更できなくなります。申請種別の遷移ルールで完了にできないタスクが含まれていると、全件とも完了になりません。"
        confirmLabel={`${dialogTasks.length}件を完了にする`}
        resultTitle="完了の結果"
        onExecute={executeBulkComplete}
        onSucceeded={removeFromSelection}
        successMessage={(count) => `${count}件のタスクを完了にしました`}
      />
    </div>
  )
}
