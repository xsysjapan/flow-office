import type { BadgeTone } from '../components/Badge/Badge'
import type { AttendanceDayStatus, AttendanceMonthStatus, WorkflowRequestStatus } from '../api/types'

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

export function workflowRequestStatusLabel(status: WorkflowRequestStatus): StatusMeta {
  return workflowRequestStatusMeta[status]
}

export function attendanceMonthStatusLabel(status: AttendanceMonthStatus): StatusMeta {
  return attendanceMonthStatusMeta[status]
}

export function attendanceDayStatusLabel(status: AttendanceDayStatus): StatusMeta {
  return attendanceDayStatusMeta[status]
}
