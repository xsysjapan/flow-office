import type { BadgeTone } from '../components/Badge/Badge'
import type {
  AttendanceDayStatus,
  AttendanceMonthStatus,
  BackOfficeTaskStatus,
  PaidLeaveRequestStatus,
  PaidLeaveType,
  WorkflowRequestStatus,
} from '../api/types'

interface StatusMeta {
  label: string
  tone: BadgeTone
}

const workflowRequestStatusMeta: Record<WorkflowRequestStatus, StatusMeta> = {
  draft: { label: '下書き', tone: 'neutral' },
  submitted: { label: '提出済み', tone: 'info' },
  approved: { label: '承認済み', tone: 'success' },
  returned: { label: '差戻し', tone: 'warning' },
  cancelled: { label: '取消', tone: 'danger' },
}

const attendanceMonthStatusMeta: Record<AttendanceMonthStatus, StatusMeta> = {
  not_submitted: { label: '未提出', tone: 'neutral' },
  submitted: { label: '提出済み', tone: 'info' },
  approved: { label: '承認済み', tone: 'success' },
  returned: { label: '差戻し', tone: 'warning' },
  closed: { label: '締め済み', tone: 'success' },
}

const attendanceDayStatusMeta: Record<AttendanceDayStatus, StatusMeta> = {
  not_started: { label: '未出勤', tone: 'neutral' },
  working: { label: '勤務中', tone: 'info' },
  on_break: { label: '休憩中', tone: 'warning' },
  clocked_out: { label: '退勤済み', tone: 'success' },
}

const paidLeaveRequestStatusMeta: Record<PaidLeaveRequestStatus, StatusMeta> = {
  submitted: { label: '申請中', tone: 'info' },
  approved: { label: '承認済み', tone: 'success' },
  returned: { label: '差戻し', tone: 'warning' },
  cancelled: { label: '取消', tone: 'danger' },
}

const paidLeaveTypeLabels: Record<PaidLeaveType, string> = {
  full: '全休',
  am_half: '午前半休',
  pm_half: '午後半休',
  hourly: '時間休',
}

const backOfficeTaskStatusMeta: Record<BackOfficeTaskStatus, StatusMeta> = {
  not_started: { label: '未着手', tone: 'neutral' },
  in_review: { label: '確認中', tone: 'info' },
  needs_fix: { label: '要修正', tone: 'warning' },
  processing: { label: '処理中', tone: 'info' },
  ordered: { label: '発注済み', tone: 'info' },
  payment_scheduled: { label: '支払予定', tone: 'info' },
  shipped: { label: '発送済み', tone: 'success' },
  completed: { label: '完了', tone: 'success' },
  cancelled: { label: '取消', tone: 'danger' },
}

export function workflowRequestStatusLabel(status: WorkflowRequestStatus): StatusMeta {
  return workflowRequestStatusMeta[status]
}

export function attendanceMonthStatusLabel(status: AttendanceMonthStatus): StatusMeta {
  return attendanceMonthStatusMeta[status]
}

export function attendanceDayStatusLabel(status: AttendanceDayStatus): StatusMeta {
  return attendanceDayStatusMeta[status]
}

export function backOfficeTaskStatusLabel(status: BackOfficeTaskStatus): StatusMeta {
  return backOfficeTaskStatusMeta[status]
}

export function paidLeaveRequestStatusLabel(status: PaidLeaveRequestStatus): StatusMeta {
  return paidLeaveRequestStatusMeta[status]
}

export function paidLeaveTypeLabel(leaveType: PaidLeaveType): string {
  return paidLeaveTypeLabels[leaveType]
}

const workflowRequestEventTypeLabels: Record<string, string> = {
  'workflow_request.drafted': '下書き作成',
  'workflow_request.submitted': '提出',
  'workflow_request.approved': '承認',
  'workflow_request.returned': '差戻し',
  'workflow_request.cancelled': '取消',
}

export function workflowRequestEventTypeLabel(eventType: string): string {
  return workflowRequestEventTypeLabels[eventType] ?? eventType
}
