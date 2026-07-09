import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { Badge } from '../components/Badge/Badge'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { FormField } from '../components/FormField/FormField'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { UserPicker } from '../components/UserPicker/UserPicker'
import type { BackOfficeTaskStatus } from '../api/types'
import {
  useAssignBackOfficeTask,
  useBackOfficeTask,
  useChangeBackOfficeTaskStatus,
} from '../hooks/useBackOfficeTasks'
import { backOfficeTaskStatusLabel } from '../utils/statusLabels'
import './BackOfficeTaskDetailPage.css'

const STATUS_OPTIONS: BackOfficeTaskStatus[] = [
  'not_started',
  'in_review',
  'needs_fix',
  'processing',
  'ordered',
  'payment_scheduled',
  'shipped',
  'completed',
  'cancelled',
]

/**
 * UC-11: バックオフィス処理タスクの詳細確認・担当者割り当て・状態更新。
 */
export function BackOfficeTaskDetailPage() {
  const { id } = useParams<{ id: string }>()
  const taskId = Number(id)
  const { data: task, isLoading, error } = useBackOfficeTask(taskId)

  const assignTask = useAssignBackOfficeTask()
  const changeStatus = useChangeBackOfficeTaskStatus()

  const [assignedUserId, setAssignedUserId] = useState<number | undefined>(undefined)
  const [status, setStatus] = useState<BackOfficeTaskStatus>('not_started')
  const [comment, setComment] = useState('')

  useEffect(() => {
    if (task?.status) setStatus(task.status)
  }, [task?.status])

  if (isLoading) return <LoadingState />
  if (error) return <ErrorMessage error={error} fallback="タスクの取得に失敗しました。" />
  if (!task) return null

  const { label, tone } = backOfficeTaskStatusLabel(task.status)
  const actionError = assignTask.error ?? changeStatus.error

  return (
    <Card title={task.title} actions={<Badge tone={tone}>{label}</Badge>}>
      {actionError && <ErrorMessage error={actionError} />}

      <dl className="backoffice-task-detail__meta">
        <dt>種別</dt>
        <dd>{task.task_type}</dd>
        <dt>元データ</dt>
        <dd>
          {task.source_type} #{task.source_id}
        </dd>
        <dt>担当部署</dt>
        <dd>{task.assigned_department ?? '未設定'}</dd>
        <dt>担当者</dt>
        <dd>{task.assignee?.name ?? '未割り当て'}</dd>
        <dt>期限</dt>
        <dd>{task.due_on ?? '未設定'}</dd>
        <dt>完了日時</dt>
        <dd>{task.completed_at ?? '-'}</dd>
      </dl>

      {!task.assignee && (
        <div className="backoffice-task-detail__assign">
          <h3>担当者を割り当てる</h3>
          <FormField label="担当者" htmlFor="assignee">
            <UserPicker id="assignee" value={assignedUserId} onChange={setAssignedUserId} />
          </FormField>
          <Button
            isLoading={assignTask.isPending}
            disabled={!assignedUserId}
            onClick={() => assignTask.mutate({ id: taskId, assignedUserId: assignedUserId! })}
          >
            割り当てる
          </Button>
        </div>
      )}

      <div className="backoffice-task-detail__status-change">
        <h3>状態を変更する</h3>
        <div className="backoffice-task-detail__status-change-row">
          <FormField label="状態" htmlFor="status">
            <select
              id="status"
              value={status}
              onChange={(e) => setStatus(e.target.value as BackOfficeTaskStatus)}
            >
              {STATUS_OPTIONS.map((option) => (
                <option key={option} value={option}>
                  {backOfficeTaskStatusLabel(option).label}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="コメント(任意)" htmlFor="status-comment">
            <input id="status-comment" value={comment} onChange={(e) => setComment(e.target.value)} />
          </FormField>
          <Button
            isLoading={changeStatus.isPending}
            onClick={() =>
              changeStatus.mutate({ id: taskId, status, comment: comment || undefined })
            }
          >
            更新する
          </Button>
        </div>
      </div>
    </Card>
  )
}
