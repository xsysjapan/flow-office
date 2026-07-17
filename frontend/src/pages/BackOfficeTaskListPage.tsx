import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Badge } from '../components/Badge/Badge'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { Checkbox } from '../components/ui/checkbox'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../components/ui/table'
import { UserPicker } from '../components/UserPicker/UserPicker'
import type { BackOfficeTask } from '../api/types'
import { useAssignBackOfficeTask, useMyBackOfficeTasks, useUnassignedBackOfficeTasks } from '../hooks/useBackOfficeTasks'
import { backOfficeTaskStatusLabel } from '../utils/statusLabels'

interface BackOfficeTaskTableProps {
  tasks: BackOfficeTask[]
  selectedIds?: Set<string>
  onToggleRow?: (id: string) => void
}

/** selectedIds/onToggleRowを渡した場合のみ、行選択用のチェックボックス列を表示する。 */
function BackOfficeTaskTable({ tasks, selectedIds, onToggleRow }: BackOfficeTaskTableProps) {
  const selectable = Boolean(selectedIds && onToggleRow)

  return (
    <Table>
      <TableHeader>
        <TableRow>
          {selectable && <TableHead aria-hidden="true" />}
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
          return (
            <TableRow key={task.id} data-state={selected ? 'selected' : undefined}>
              {selectable && (
                <TableCell>
                  <Checkbox
                    checked={selected}
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
  const unassigned = useUnassignedBackOfficeTasks()
  const mine = useMyBackOfficeTasks()
  const assignTask = useAssignBackOfficeTask()

  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set())
  const [bulkAssignee, setBulkAssignee] = useState<number | undefined>(undefined)
  const [isBulkAssigning, setIsBulkAssigning] = useState(false)
  const [bulkError, setBulkError] = useState<Error | null>(null)

  if (unassigned.isLoading || mine.isLoading) return <LoadingState />
  if (unassigned.error) {
    return <ErrorMessage error={unassigned.error} fallback="未割り当てタスクの取得に失敗しました。" />
  }
  if (mine.error) return <ErrorMessage error={mine.error} fallback="自分のタスクの取得に失敗しました。" />

  const unassignedTasks = unassigned.data?.data ?? []
  const myTasks = mine.data?.data ?? []

  function toggleRow(id: string) {
    setSelectedIds((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  async function handleBulkAssign() {
    if (!bulkAssignee || selectedIds.size === 0) return
    setBulkError(null)
    setIsBulkAssigning(true)
    try {
      await Promise.all(
        Array.from(selectedIds).map((id) => assignTask.mutateAsync({ id, assignedUserId: bulkAssignee })),
      )
      setSelectedIds(new Set())
      setBulkAssignee(undefined)
    } catch (e) {
      setBulkError(e as Error)
    } finally {
      setIsBulkAssigning(false)
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <Card
        title="未割り当てタスク"
        actions={
          selectedIds.size > 0 ? (
            <div className="flex items-center gap-2">
              <span className="text-sm whitespace-nowrap text-muted-foreground">{selectedIds.size}件を選択中</span>
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
          <BackOfficeTaskTable tasks={unassignedTasks} selectedIds={selectedIds} onToggleRow={toggleRow} />
        )}
      </Card>

      <Card title="自分のタスク">
        {myTasks.length === 0 ? (
          <p className="text-sm text-muted-foreground">担当中のタスクはありません。</p>
        ) : (
          <BackOfficeTaskTable tasks={myTasks} />
        )}
      </Card>
    </div>
  )
}
