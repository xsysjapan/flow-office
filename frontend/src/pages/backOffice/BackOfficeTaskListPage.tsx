import { type Dispatch, type FormEvent, type SetStateAction, useState } from 'react'
import { Link } from 'react-router-dom'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Pagination } from '../../components/Pagination/Pagination'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import type { BackOfficeTask } from '../../api/types'
import {
  useAssignBackOfficeTask,
  useBulkCompleteBackOfficeTasks,
  useMyBackOfficeTasks,
  useUnassignedBackOfficeTasks,
} from '../../hooks/useBackOfficeTasks'
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
            <TableRow key={task.id} data-state={selected ? 'selected' : undefined}>
              {selectable && (
                <TableCell>
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
                  className="font-medium text-foreground hover:text-primary hover:underline"
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
            </TableRow>
          )
        })}
      </TableBody>
    </Table>
  )
}

/**
 * UC-11: バックオフィス処理タスクの一覧(未割り当て / 自分のタスク)。
 * 未割り当てタスクは複数選択し、担当者を指定してまとめて割り当てられる(オブジェクトを
 * 選択してから操作を適用するUI)。
 */
export function BackOfficeTaskListPage() {
  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [unassignedPage, setUnassignedPage] = useState(1)
  const [minePage, setMinePage] = useState(1)
  const unassigned = useUnassignedBackOfficeTasks({ search: search || undefined, page: unassignedPage })
  const mine = useMyBackOfficeTasks({ search: search || undefined, page: minePage })
  const assignTask = useAssignBackOfficeTask()
  const bulkComplete = useBulkCompleteBackOfficeTasks()

  const [selectedUnassignedIds, setSelectedUnassignedIds] = useState<Set<string>>(new Set())
  const [selectedMyTaskIds, setSelectedMyTaskIds] = useState<Set<string>>(new Set())
  const [bulkAssignee, setBulkAssignee] = useState<string | undefined>(undefined)
  const [isBulkAssigning, setIsBulkAssigning] = useState(false)
  const [bulkError, setBulkError] = useState<Error | null>(null)

  if (unassigned.isLoading || mine.isLoading) return <LoadingState />
  if (unassigned.error) {
    return <ErrorMessage error={unassigned.error} fallback="未割り当てタスクの取得に失敗しました。" />
  }
  if (mine.error) return <ErrorMessage error={mine.error} fallback="自分のタスクの取得に失敗しました。" />

  const unassignedTasks = unassigned.data?.data ?? []
  const myTasks = mine.data?.data ?? []

  function toggleRow(setter: Dispatch<SetStateAction<Set<string>>>, id: string) {
    setter((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  function toggleAll(setter: Dispatch<SetStateAction<Set<string>>>, ids: string[]) {
    setter((prev) => {
      const next = new Set(prev)
      const shouldSelect = ids.some((id) => !next.has(id))
      ids.forEach((id) => (shouldSelect ? next.add(id) : next.delete(id)))
      return next
    })
  }

  async function handleBulkAssign() {
    if (!bulkAssignee || selectedUnassignedIds.size === 0) return
    setBulkError(null)
    setIsBulkAssigning(true)
    try {
      await Promise.all(
        Array.from(selectedUnassignedIds).map((id) => assignTask.mutateAsync({ id, assignedUserId: bulkAssignee })),
      )
      setSelectedUnassignedIds(new Set())
      setBulkAssignee(undefined)
    } catch (e) {
      setBulkError(e as Error)
    } finally {
      setIsBulkAssigning(false)
    }
  }

  async function handleBulkComplete() {
    if (selectedMyTaskIds.size === 0) return
    await bulkComplete.mutateAsync(Array.from(selectedMyTaskIds))
    setSelectedMyTaskIds(new Set())
  }

  function handleSearch(event: FormEvent) {
    event.preventDefault()
    setSearch(searchInput.trim())
    setUnassignedPage(1)
    setMinePage(1)
    setSelectedUnassignedIds(new Set())
    setSelectedMyTaskIds(new Set())
  }

  return (
    <div className="flex flex-col gap-6">
      <Card title="タスク検索">
        <form className="flex flex-col gap-2 sm:flex-row" onSubmit={handleSearch}>
          <Input
            value={searchInput}
            onChange={(event) => setSearchInput(event.target.value)}
            aria-label="タスクを検索"
            placeholder="タイトル・種別・担当部署・担当者名で検索"
          />
          <Button type="submit">検索</Button>
          {search && (
            <Button
              type="button"
              variant="secondary"
              onClick={() => {
                setSearchInput('')
                setSearch('')
                setUnassignedPage(1)
                setMinePage(1)
              }}
            >
              クリア
            </Button>
          )}
        </form>
      </Card>

      <Card
        title="未割り当てタスク"
        actions={
          selectedUnassignedIds.size > 0 ? (
            <div className="flex items-center gap-2">
              <span className="text-sm whitespace-nowrap text-muted-foreground">{selectedUnassignedIds.size}件を選択中</span>
              <div className="w-56">
                <UserPicker id="bulk-assignee" value={bulkAssignee} onChange={setBulkAssignee} placeholder="担当者を選択" />
              </div>
              <Button onClick={() => void handleBulkAssign()} isLoading={isBulkAssigning} disabled={!bulkAssignee}>
                割り当てる
              </Button>
            </div>
          ) : undefined
        }
      >
        {bulkError && <ErrorMessage error={bulkError} />}
        {unassignedTasks.length === 0 ? (
          <p className="text-sm text-muted-foreground">未割り当てのタスクはありません。</p>
        ) : (
          <>
            <BackOfficeTaskTable
              tasks={unassignedTasks}
              selectedIds={selectedUnassignedIds}
              onToggleRow={(id) => toggleRow(setSelectedUnassignedIds, id)}
              onToggleAll={(ids) => toggleAll(setSelectedUnassignedIds, ids)}
            />
            {unassigned.data && (
              <Pagination
                currentPage={unassigned.data.meta.current_page}
                lastPage={unassigned.data.meta.last_page}
                total={unassigned.data.meta.total}
                onPageChange={setUnassignedPage}
              />
            )}
          </>
        )}
      </Card>

      <Card
        title="自分のタスク"
        actions={
          selectedMyTaskIds.size > 0 ? (
            <div className="flex items-center gap-2">
              <span className="text-sm whitespace-nowrap text-muted-foreground">{selectedMyTaskIds.size}件を選択中</span>
              <Button isLoading={bulkComplete.isPending} onClick={() => void handleBulkComplete()}>
                選択したタスクを完了
              </Button>
            </div>
          ) : undefined
        }
      >
        {bulkComplete.error && <ErrorMessage error={bulkComplete.error} fallback="タスクの一括完了に失敗しました。" />}
        {myTasks.length === 0 ? (
          <p className="text-sm text-muted-foreground">担当中のタスクはありません。</p>
        ) : (
          <>
            <BackOfficeTaskTable
              tasks={myTasks}
              selectedIds={selectedMyTaskIds}
              onToggleRow={(id) => toggleRow(setSelectedMyTaskIds, id)}
              onToggleAll={(ids) => toggleAll(setSelectedMyTaskIds, ids)}
              isRowSelectable={(task) => !['completed', 'cancelled'].includes(task.status)}
            />
            {mine.data && (
              <Pagination
                currentPage={mine.data.meta.current_page}
                lastPage={mine.data.meta.last_page}
                total={mine.data.meta.total}
                onPageChange={setMinePage}
              />
            )}
          </>
        )}
      </Card>
    </div>
  )
}
