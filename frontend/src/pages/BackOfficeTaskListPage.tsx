import { Link } from 'react-router-dom'
import { Badge } from '../components/Badge/Badge'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../components/LoadingState/LoadingState'
import type { BackOfficeTask } from '../api/types'
import { useMyBackOfficeTasks, useUnassignedBackOfficeTasks } from '../hooks/useBackOfficeTasks'
import { backOfficeTaskStatusLabel } from '../utils/statusLabels'
import './BackOfficeTaskListPage.css'

function BackOfficeTaskRow({ task }: { task: BackOfficeTask }) {
  const { label, tone } = backOfficeTaskStatusLabel(task.status)

  return (
    <li>
      <div>
        <Link to={`/backoffice-tasks/${task.id}`}>{task.title}</Link>
        <span className="backoffice-task-list__type">{task.task_type}</span>
        {task.assignee && <span className="backoffice-task-list__assignee">{task.assignee.name}</span>}
      </div>
      <div className="backoffice-task-list__meta">
        {task.due_on && <span className="backoffice-task-list__due">期限: {task.due_on}</span>}
        <Badge tone={tone}>{label}</Badge>
      </div>
    </li>
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
    <div className="backoffice-task-list-page">
      <Card title="未割り当てタスク">
        {unassignedTasks.length === 0 ? (
          <p>未割り当てのタスクはありません。</p>
        ) : (
          <ul className="backoffice-task-list">
            {unassignedTasks.map((task) => (
              <BackOfficeTaskRow key={task.id} task={task} />
            ))}
          </ul>
        )}
      </Card>

      <Card title="自分のタスク">
        {myTasks.length === 0 ? (
          <p>担当中のタスクはありません。</p>
        ) : (
          <ul className="backoffice-task-list">
            {myTasks.map((task) => (
              <BackOfficeTaskRow key={task.id} task={task} />
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}
