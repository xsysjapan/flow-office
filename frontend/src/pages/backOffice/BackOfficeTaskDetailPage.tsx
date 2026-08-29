import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ApiError } from '../../api/client'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { PermissionDenied } from '../../components/PermissionDenied/PermissionDenied'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import type { BackOfficeTaskStatus } from '../../api/types'
import {
  useAssignBackOfficeTask,
  useBackOfficeTask,
  useChangeBackOfficeTaskStatus,
} from '../../hooks/useBackOfficeTasks'
import { useAttendanceMonthById } from '../../hooks/useAttendance'
import { backOfficeTaskStatusLabel } from '../../utils/statusLabels'
import { AttendanceMonthReferenceTabs } from '../attendance/AttendanceReferencePage'

/**
 * 月次勤怠承認(attendance.month_approved)から自動生成されたバックオフィスタスク専用の
 * 締め処理セクション。CSV/Excel出力・締める/締めを取り消すは管理画面の勤怠参照機能
 * (AttendanceReferencePage)と同一のAttendanceMonthReferenceTabsが権限に応じて表示するため、
 * ここでは対象月の解決だけを行う薄いラッパーにする。
 */
function AttendanceMonthConfirmationSection({ attendanceMonthId }: { attendanceMonthId: string }) {
  const { data: month, isLoading, error } = useAttendanceMonthById(attendanceMonthId)

  return (
    <div className="mt-5 border-t border-border pt-4">
      <h3 className="mb-3 text-sm font-semibold text-foreground">月次勤怠の締め処理</h3>

      {isLoading ? (
        <LoadingState />
      ) : error ? (
        <ErrorMessage error={error} fallback="月次勤怠の取得に失敗しました。" />
      ) : month ? (
        <AttendanceMonthReferenceTabs userId={month.user_id} yearMonth={month.year_month} />
      ) : null}
    </div>
  )
}

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
  const taskId = id ?? ''
  const { data: task, isLoading, error } = useBackOfficeTask(taskId)

  const assignTask = useAssignBackOfficeTask()
  const changeStatus = useChangeBackOfficeTaskStatus()

  const [assignedUserId, setAssignedUserId] = useState<string | undefined>(undefined)
  const [status, setStatus] = useState<BackOfficeTaskStatus>('not_started')
  const [comment, setComment] = useState('')

  useEffect(() => {
    if (task?.status) setStatus(task.status)
  }, [task?.status])

  if (isLoading) return <LoadingState />
  if (error) {
    if (error instanceof ApiError && error.status === 403) return <PermissionDenied />
    return <ErrorMessage error={error} fallback="タスクの取得に失敗しました。" />
  }
  if (!task) return null

  const { label, tone } = backOfficeTaskStatusLabel(task.status)
  const actionError = assignTask.error ?? changeStatus.error

  return (
    <div className="flex flex-col gap-3">
      <Link
        to="/backoffice-tasks"
        className="text-sm text-muted-foreground hover:text-foreground hover:underline"
      >
        ← タスク一覧に戻る
      </Link>
      <Card title={task.title} actions={<Badge tone={tone}>{label}</Badge>}>
        {actionError && <ErrorMessage error={actionError} />}

        <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
          <dt className="font-medium text-muted-foreground">種別</dt>
          <dd className="text-foreground">{task.task_type}</dd>
          <dt className="font-medium text-muted-foreground">元データ</dt>
          <dd className="text-foreground">
            {task.source_type} #{task.source_id}
          </dd>
          <dt className="font-medium text-muted-foreground">担当部署</dt>
          <dd className="text-foreground">{task.assigned_department ?? '未設定'}</dd>
          <dt className="font-medium text-muted-foreground">担当者</dt>
          <dd className="text-foreground">{task.assignee?.name ?? '未割り当て'}</dd>
          <dt className="font-medium text-muted-foreground">期限</dt>
          <dd className="text-foreground">{task.due_on ?? '未設定'}</dd>
          <dt className="font-medium text-muted-foreground">完了日時</dt>
          <dd className="text-foreground">{task.completed_at ?? '-'}</dd>
        </dl>

        {!task.assignee && (
          <div className="mt-5 border-t border-border pt-4">
            <h3 className="mb-3 text-sm font-semibold text-foreground">担当者を割り当てる</h3>
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
            {!assignedUserId && <p className="text-xs text-muted-foreground">担当者を選択してください</p>}
          </div>
        )}

        <div className="mt-5 border-t border-border pt-4">
          <h3 className="mb-3 text-sm font-semibold text-foreground">状態を変更する</h3>
          <div className="flex flex-wrap items-end gap-3">
            <FormField label="状態" htmlFor="status">
              <NativeSelect id="status" value={status} onChange={(e) => setStatus(e.target.value as BackOfficeTaskStatus)}>
                {STATUS_OPTIONS.map((option) => (
                  <option key={option} value={option}>
                    {backOfficeTaskStatusLabel(option).label}
                  </option>
                ))}
              </NativeSelect>
            </FormField>
            <FormField label="コメント(任意)" htmlFor="status-comment">
              <Input id="status-comment" value={comment} onChange={(e) => setComment(e.target.value)} />
            </FormField>
            <Button
              isLoading={changeStatus.isPending}
              onClick={() => changeStatus.mutate({ id: taskId, status, comment: comment || undefined })}
            >
              更新する
            </Button>
          </div>
        </div>

        {task.task_type === 'attendance_month_confirmation' && task.source_type === 'attendance_month' && (
          <AttendanceMonthConfirmationSection attendanceMonthId={task.source_id} />
        )}
      </Card>
    </div>
  )
}
