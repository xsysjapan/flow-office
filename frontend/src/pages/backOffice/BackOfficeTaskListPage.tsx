import { type FormEvent, useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useForwardState, useSearchParamsKeepingBackState } from '../../navigation/backState'
import { ApiError } from '../../api/client'
import { useAuth } from '../../auth/useAuth'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ClickableTableRow } from '../../components/ClickableTableRow/ClickableTableRow'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Pagination } from '../../components/Pagination/Pagination'
import { PermissionDenied } from '../../components/PermissionDenied/PermissionDenied'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import { Tabs, TabsList, TabsTrigger } from '../../components/ui/tabs'
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
          <TableHead>タイトル</TableHead>
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
              <TableCell>
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

  const [searchInput, setSearchInput] = useState(search)
  useEffect(() => setSearchInput(search), [search])

  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set())
  const [bulkAssignee, setBulkAssignee] = useState<string | undefined>(undefined)
  const [isBulkAssigning, setIsBulkAssigning] = useState(false)
  const [bulkError, setBulkError] = useState<Error | null>(null)

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
  if (tasksQuery.error) {
    if (tasksQuery.error instanceof ApiError && tasksQuery.error.status === 403) return <PermissionDenied />
    return <ErrorMessage error={tasksQuery.error} fallback="タスクの取得に失敗しました。" />
  }
  // 初回読み込みのみ画面全体をLoadingにする(条件切替中はplaceholderDataで前の結果を残す)
  if (!result) return <LoadingState />

  const tasks = result.data
  const counts = result.meta.assignee_counts
  const selectedCount = selectedIds.size
  const selectedTasks = tasks.filter((task) => selectedIds.has(task.id))

  // 担当者の設定は選択可能な(完了・取消以外の)タスクなら担当者の状態に関係なく可能。完了は自分のタスクだけ(自分の判定は担当者IDの一致)
  const isMine = (task: BackOfficeTask) => user !== null && task.assignee?.id === user.id
  const allMine = selectedTasks.every(isMine)
  const assignReason = !bulkAssignee ? '担当者を選択してください' : undefined
  const canAssign = assignReason === undefined
  const completeReason = allMine ? undefined : '自分のタスクのみ一括完了できます'
  const canComplete = completeReason === undefined

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

  function submitSearch(event: FormEvent) {
    event.preventDefault()
    updateParams({ q: searchInput.trim() || null, page: null })
    clearSelection()
  }

  /** 検索語とステータスを既定に戻す。担当者タブは維持する。 */
  function clearConditions() {
    setSearchInput('')
    updateParams({ q: null, status: null, page: null })
    clearSelection()
  }

  async function handleBulkAssign() {
    if (!bulkAssignee || !canAssign) return
    setBulkError(null)
    setIsBulkAssigning(true)
    try {
      await Promise.all(
        Array.from(selectedIds).map((id) => assignTask.mutateAsync({ id, assignedUserId: bulkAssignee })),
      )
      clearSelection()
      setBulkAssignee(undefined)
    } catch (e) {
      setBulkError(e as Error)
    } finally {
      setIsBulkAssigning(false)
    }
  }

  async function handleBulkComplete() {
    if (!canComplete) return
    try {
      await bulkComplete.mutateAsync(Array.from(selectedIds))
      clearSelection()
    } catch {
      // 失敗時の表示はbulkComplete.errorでErrorMessageに出す
    }
  }

  const currentTab = ASSIGNEE_TABS.find((tab) => tab.scope === assignee) ?? ASSIGNEE_TABS[0]

  return (
    <div className="flex flex-col gap-6">
      <Card title="タスク検索">
        <form className="flex flex-col gap-2 sm:flex-row" onSubmit={submitSearch}>
          <Input
            value={searchInput}
            onChange={(event) => setSearchInput(event.target.value)}
            aria-label="タスクを検索"
            placeholder="タイトル・種別・担当部署・担当者名で検索"
          />
          <NativeSelect
            aria-label="ステータス"
            className="sm:w-44"
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
          <Button type="submit">検索</Button>
          {isFiltered && (
            <Button type="button" variant="secondary" onClick={clearConditions}>
              クリア
            </Button>
          )}
        </form>
      </Card>

      <Tabs
        value={assignee}
        onValueChange={(value) => {
          if (isOneOf(value, ASSIGNEE_SCOPES)) changeAssignee(value)
        }}
      >
        <div className="max-w-full overflow-x-auto">
          <TabsList>
            {ASSIGNEE_TABS.map((tab) => {
              const count = counts[tab.scope]
              return (
                <TabsTrigger
                  key={tab.scope}
                  value={tab.scope}
                  aria-label={`${tab.label}(${count}件)`}
                >
                  {tab.label} {count}
                </TabsTrigger>
              )
            })}
          </TabsList>
        </div>
      </Tabs>

      <Card>
        {selectedCount > 0 && (
          <div className="mb-4 flex w-full flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
            <div className="flex items-center justify-between gap-2 sm:contents">
              <span className="text-sm whitespace-nowrap text-muted-foreground">{selectedCount}件を選択中</span>
              <Button variant="secondary" size="sm" className="sm:order-last" onClick={clearSelection}>
                キャンセル
              </Button>
            </div>
            <div className="-mx-1 flex items-center gap-2 overflow-x-auto px-1 sm:mx-0 sm:overflow-visible sm:px-0">
              <div className="w-56 shrink-0">
                <UserPicker id="bulk-assignee" value={bulkAssignee} onChange={setBulkAssignee} placeholder="担当者を選択" />
              </div>
              <Button
                size="sm"
                className="shrink-0"
                onClick={() => void handleBulkAssign()}
                isLoading={isBulkAssigning}
                disabled={!canAssign}
              >
                担当者を設定
              </Button>
              <Button
                size="sm"
                className="shrink-0"
                onClick={() => void handleBulkComplete()}
                isLoading={bulkComplete.isPending}
                disabled={!canComplete}
              >
                選択したタスクを完了
              </Button>
            </div>
            {(assignReason || completeReason) && (
              <div className="flex flex-col text-xs text-muted-foreground">
                {assignReason && <p>{assignReason}</p>}
                {completeReason && <p>{completeReason}</p>}
              </div>
            )}
          </div>
        )}
        {bulkError && <ErrorMessage error={bulkError} />}
        {bulkComplete.error && <ErrorMessage error={bulkComplete.error} fallback="タスクの一括完了に失敗しました。" />}

        {tasks.length === 0 ? (
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
      </Card>
    </div>
  )
}
