import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import { ApiError } from '../../api/client'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ConfirmActionDialog } from '../../components/ConfirmActionDialog/ConfirmActionDialog'
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
import {
  useAttendanceMonthById,
  useCloseMonth,
  useDownloadAttendanceCsv,
  useDownloadAttendanceExcel,
} from '../../hooks/useAttendance'
import { backOfficeTaskStatusLabel } from '../../utils/statusLabels'
import { AttendanceMonthReferenceTabs, ReopenMonthDialog } from '../attendance/AttendanceReferencePage'

/**
 * 月次勤怠承認(attendance.month_approved)から自動生成されたバックオフィスタスク専用の
 * 締め処理セクション。汎用ステータス変更フォームとは独立させ、締め済みなら編集不可であることを
 * 明示する(絶対に外してはいけない設計原則3: 月次側は日次実績の集計・確定結果であり、
 * ここでは締めるかどうかだけを操作対象にする)。締めは不可逆操作のため、締める前に
 * 対象社員の実績・法定休日警告を確認できるようにする(旧AttendanceMonthCloseoutPageに
 * あった確認導線を統合)。
 */
function AttendanceMonthConfirmationSection({ attendanceMonthId }: { attendanceMonthId: string }) {
  const { user } = useAuth()
  const canReopenMonth = user?.effective_permissions?.includes('attendance.month_reopen') ?? false
  const { data: month, isLoading, error, refetch } = useAttendanceMonthById(attendanceMonthId)
  const closeMonth = useCloseMonth()
  const downloadCsv = useDownloadAttendanceCsv()
  const downloadExcel = useDownloadAttendanceExcel()

  return (
    <div className="mt-5 border-t border-border pt-4">
      <h3 className="mb-3 text-sm font-semibold text-foreground">月次勤怠の締め処理</h3>

      {downloadCsv.error && <ErrorMessage error={downloadCsv.error} fallback="勤怠CSVの取得に失敗しました。" />}
      {downloadExcel.error && <ErrorMessage error={downloadExcel.error} fallback="勤怠Excelの取得に失敗しました。" />}

      {isLoading ? (
        <LoadingState />
      ) : error ? (
        <ErrorMessage error={error} fallback="月次勤怠の取得に失敗しました。" />
      ) : month ? (
        <div className="flex flex-col gap-4">
          <div className="flex flex-col gap-4 rounded-md border border-border p-3">
            <AttendanceMonthReferenceTabs userId={month.user_id} yearMonth={month.year_month} />
          </div>
          <div className="flex flex-wrap gap-2">
            <Button
              variant="secondary"
              isLoading={downloadCsv.isPending}
              onClick={() =>
                downloadCsv.mutate({ year_month: [month.year_month], user_id: [month.user_id], format: 'generic' })
              }
            >
              CSV出力
            </Button>
            <Button
              variant="secondary"
              isLoading={downloadExcel.isPending}
              onClick={() => downloadExcel.mutate({ year_month: [month.year_month], user_id: [month.user_id] })}
            >
              Excel出力
            </Button>
          </div>
          {month.status === 'closed' ? (
            <div className="flex flex-col gap-3">
              <p className="text-sm text-muted-foreground">
                締め処理済みのため修正できません。月次勤怠の内容は引き続き確認できます。
              </p>
              {canReopenMonth && <ReopenMonthDialog monthId={month.id} yearMonth={month.year_month} />}
            </div>
          ) : (
            <ConfirmActionDialog
              triggerLabel="締める"
              triggerVariant="primary"
              title="月次勤怠を締めますか?"
              description={`${month.year_month}の月次勤怠を締めます。締めた後は日次実績を編集できなくなります。この操作は元に戻せません。`}
              confirmLabel="締めを確定する"
              isPending={closeMonth.isPending}
              error={closeMonth.error}
              onConfirm={() =>
                closeMonth.mutateAsync(attendanceMonthId).then(() => {
                  void refetch()
                })
              }
            />
          )}
        </div>
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
