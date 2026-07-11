import type { BadgeTone } from '../components/Badge/Badge'
import type {
  AttendanceDayStatus,
  AttendanceMonthStatus,
  BackOfficeTaskStatus,
  PaidLeaveRequestStatus,
  PaidLeaveType,
  PunchStatus,
  PunchType,
  StoredEvent,
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

const punchTypeLabels: Record<PunchType, string> = {
  clock_in: '出勤',
  break_start: '休憩開始',
  break_end: '休憩終了',
  clock_out: '退勤',
}

const punchStatusMeta: Record<PunchStatus, StatusMeta> = {
  active: { label: '有効', tone: 'neutral' },
  corrected: { label: '訂正済み', tone: 'info' },
  deleted: { label: '削除済み', tone: 'danger' },
}

export function punchTypeLabel(type: PunchType): string {
  return punchTypeLabels[type]
}

export function punchStatusLabel(status: PunchStatus): StatusMeta {
  return punchStatusMeta[status]
}

const paidLeaveEventTypeMeta: Record<string, StatusMeta> = {
  'paid_leave.granted': { label: '付与', tone: 'success' },
  'paid_leave.requested': { label: '申請', tone: 'info' },
  'paid_leave.request_approved': { label: '承認', tone: 'success' },
  'paid_leave.request_returned': { label: '差戻し', tone: 'warning' },
  'paid_leave.request_cancelled': { label: '取消', tone: 'danger' },
  'paid_leave.used': { label: '消化', tone: 'info' },
  'paid_leave.warning_raised': { label: '警告', tone: 'warning' },
}

export function paidLeaveEventTypeLabel(eventType: string): StatusMeta {
  return paidLeaveEventTypeMeta[eventType] ?? { label: eventType, tone: 'neutral' }
}

/**
 * UC-P007: 有給履歴の各イベントを、payloadの内容を使って人が読める1行に整形する。
 * イベントの種類ごとにpayloadの形が異なるため(docs/17-events.md参照)、
 * イベント種別で分岐して必要なフィールドだけを取り出す。
 */
export function paidLeaveEventDetail(event: StoredEvent): string {
  const payload = event.payload

  switch (event.event_type) {
    case 'paid_leave.granted':
      return `${payload.granted_days}日を付与(有効期限 ${payload.expires_on})`
    case 'paid_leave.requested':
      return `対象日 ${payload.target_date} の${paidLeaveTypeLabel(payload.leave_type as PaidLeaveType)}を申請(${payload.requested_days}日)`
    case 'paid_leave.request_approved':
      return '有給申請が承認されました'
    case 'paid_leave.request_returned':
      return `有給申請が差し戻されました: ${payload.comment}`
    case 'paid_leave.request_cancelled':
      return '有給申請を取り消しました'
    case 'paid_leave.used':
      return `対象日 ${payload.used_on} に${payload.used_days}日を消化`
    case 'paid_leave.warning_raised':
      return String(payload.message)
    default:
      return event.event_type
  }
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
