import { Link } from 'react-router-dom'
import { Badge } from '../components/Badge/Badge'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../components/ui/table'
import type { BackOfficeTask } from '../api/types'
import { useMyBackOfficeTasks, useUnassignedBackOfficeTasks } from '../hooks/useBackOfficeTasks'
import { backOfficeTaskStatusLabel } from '../utils/statusLabels'

function BackOfficeTaskTable({ tasks }: { tasks: BackOfficeTask[] }) {
  return (
    <Table>
      <TableHeader>
        <TableRow>
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
          return (
            <TableRow key={task.id}>
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
 */
export function BackOfficeTaskListPage() {
  const unassigned = useUnassignedBackOfficeTasks()
  const mine = useMyBackOfficeTasks()

  if (unassigned.isLoading || mine.isLoading) return <LoadingState />
  if (unassigned.error) {
    return <ErrorMessage error={unassigned.error} fallback="未割り当てタスクの取得に失敗しました。" />
  }
  if (mine.error) return <ErrorMessage error={mine.error} fallback="自分のタスクの取得に失敗しました。" />

  const unassignedTasks = unassigned.data?.data ?? []
  const myTasks = mine.data?.data ?? []

  return (
    <div className="flex flex-col gap-6">
      <Card title="未割り当てタスク">
        {unassignedTasks.length === 0 ? (
          <p className="text-sm text-muted-foreground">未割り当てのタスクはありません。</p>
        ) : (
          <BackOfficeTaskTable tasks={unassignedTasks} />
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
