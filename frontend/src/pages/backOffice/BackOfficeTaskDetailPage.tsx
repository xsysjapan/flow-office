import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ApiError } from '../../api/client'
import { useAuth } from '../../auth/useAuth'
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
import { Textarea } from '../../components/ui/textarea'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import type { BackOfficeTaskStatus } from '../../api/types'
import {
  useAssignBackOfficeTask,
  useBackOfficeTask,
  useChangeBackOfficeTaskStatus,
} from '../../hooks/useBackOfficeTasks'
import { useAttendanceMonth, useAttendanceMonthById, useRevertMonthConfirmation } from '../../hooks/useAttendance'
import { useWorkflowRequest } from '../../hooks/useWorkflowRequests'
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

/**
 * UC-A018: 「勤怠確定取消依頼」ワークフロー申請の承認後に生成されるバックオフィスタスク専用の
 * 確定取消セクション。申請の`form_data`(対象年月・取消理由)から対象の月次勤怠を特定し、
 * 実際の実績はAttendanceMonthReferenceTabs(他画面と共通)で確認できるようにした上で、
 * `attendance.confirmation_revert`権限を持つ担当者のみ確定取消を実行できる。
 */
function AttendanceConfirmationRevertSection({ workflowRequestId }: { workflowRequestId: string }) {
  const { user } = useAuth()
  const canRevert = user?.effective_permissions?.includes('attendance.confirmation_revert') ?? false
  const { data: request, isLoading, error } = useWorkflowRequest(workflowRequestId)
  const targetYearMonth = request?.form_data.target_year_month
  const applicantId = request?.applicant?.id
  const { data: monthData } = useAttendanceMonth(targetYearMonth ?? '', applicantId)
  const revertConfirmation = useRevertMonthConfirmation()
  const [reason, setReason] = useState('')

  return (
    <div className="mt-5 border-t border-border pt-4">
      <h3 className="mb-3 text-sm font-semibold text-foreground">月次勤怠の確定取消</h3>

      {isLoading ? (
        <LoadingState />
      ) : error ? (
        <ErrorMessage error={error} fallback="申請の取得に失敗しました。" />
      ) : !request || !targetYearMonth || !applicantId ? (
        <p className="text-sm text-muted-foreground">対象年月・申請者を特定できませんでした。</p>
      ) : (
        <div className="flex flex-col gap-4">
          <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
            <dt className="font-medium text-muted-foreground">申請者</dt>
            <dd className="text-foreground">{request.applicant?.name}</dd>
            <dt className="font-medium text-muted-foreground">対象年月</dt>
            <dd className="text-foreground">{targetYearMonth}</dd>
            <dt className="font-medium text-muted-foreground">取消理由(申請時)</dt>
            <dd className="text-foreground">{request.form_data.reason ?? '-'}</dd>
          </dl>

          <AttendanceMonthReferenceTabs userId={applicantId} yearMonth={targetYearMonth} />

          {canRevert && monthData?.month && (
            <ConfirmActionDialog
              triggerLabel="確定を取り消す"
              triggerVariant="danger"
              title="月次勤怠の確定を取り消しますか?"
              description={`${targetYearMonth}の月次勤怠の確定を取り消し、日次実績を編集できる状態(未提出)に戻します。この操作は元に戻せません。`}
              confirmLabel="確定取消を実行する"
              isPending={revertConfirmation.isPending}
              error={revertConfirmation.error}
              onOpenChange={(open) => {
                if (open) {
                  setReason('')
                  revertConfirmation.reset()
                }
              }}
              onConfirm={() =>
                revertConfirmation.mutateAsync({
                  id: monthData.month!.id,
                  reason,
                  workflowRequestId,
                })
              }
            >
              <FormField label="取消理由" htmlFor="revert-confirmation-reason" required>
                <Textarea id="revert-confirmation-reason" value={reason} onChange={(e) => setReason(e.target.value)} rows={3} />
              </FormField>
            </ConfirmActionDialog>
          )}
        </div>
      )}
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
        {task.task_type === 'attendance_confirmation_revert' && task.source_type === 'workflow_request' && (
          <AttendanceConfirmationRevertSection workflowRequestId={task.source_id} />
        )}
      </Card>
    </div>
  )
}
