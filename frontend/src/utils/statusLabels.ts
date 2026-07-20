import type { BadgeTone } from '../components/Badge/Badge'
import type {
  AttendanceDayStatus,
  AttendanceMonthStatus,
  BackOfficeTaskStatus,
  LegalHolidayWarning,
  PaidLeaveRequestStatus,
  PaidLeaveType,
  PunchStatus,
  PunchType,
  StoredEvent,
  WorkflowRequestStatus,
  WorkLocationType,
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

/** UC-C005: シフト制勤務者の法定休日要件不足を1行の警告文言に整形する。 */
export function legalHolidayWarningLabel(warning: LegalHolidayWarning): string {
  const rule = warning.rule === 'four_weeks_four_days' ? '4週4日以上' : '毎週1日'
  return `法定休日不足(${rule}, ${warning.period_start}〜${warning.period_end}: ${warning.legal_holiday_count}/${warning.required_count}日)`
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

const workLocationTypeLabels: Record<WorkLocationType, string> = {
  office: '出社',
  remote: '在宅',
  client_site: '客先',
  business_trip: '出張',
  direct_to_site: '直行',
  direct_from_site: '直帰',
  other: 'その他',
}

/** attendance_days.work_location_type(出社/在宅/客先等)のセレクト肢一覧。 */
export const WORK_LOCATION_TYPE_OPTIONS: Array<{ value: WorkLocationType; label: string }> = (
  Object.entries(workLocationTypeLabels) as Array<[WorkLocationType, string]>
).map(([value, label]) => ({ value, label }))

export function workLocationTypeLabel(type: WorkLocationType): string {
  return workLocationTypeLabels[type]
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

/**
 * 有給・特別休暇の履歴イベントは「ドメイン.種別」の形で、末尾の種別(granted/requested/
 * request_approved等)はどちらのドメインでも共通のため、末尾だけで引き当てる
 * (Queryのみ共通化し、ビジネスロジックは別ドメインとして実装する方針に合わせた表示側の共通化)。
 */
const leaveEventSuffixMeta: Record<string, StatusMeta> = {
  granted: { label: '付与', tone: 'success' },
  requested: { label: '申請', tone: 'info' },
  request_approved: { label: '承認', tone: 'success' },
  request_returned: { label: '差戻し', tone: 'warning' },
  request_cancelled: { label: '取消', tone: 'danger' },
  used: { label: '消化', tone: 'info' },
  warning_raised: { label: '警告', tone: 'warning' },
}

function leaveEventTypeLabel(eventType: string): StatusMeta {
  const suffix = eventType.split('.').slice(1).join('.')
  return leaveEventSuffixMeta[suffix] ?? { label: eventType, tone: 'neutral' }
}

export function paidLeaveEventTypeLabel(eventType: string): StatusMeta {
  return leaveEventTypeLabel(eventType)
}

export function specialLeaveEventTypeLabel(eventType: string): StatusMeta {
  return leaveEventTypeLabel(eventType)
}

/**
 * 有給・特別休暇履歴の各イベントを、payloadの内容を使って人が読める1行に整形する。
 * イベントの種類ごとにpayloadの形が異なるため(docs/17-events.md参照)、末尾の種別
 * (leaveEventTypeLabelと同じ考え方)で分岐して必要なフィールドだけを取り出す。
 * 有給には無い(法定の時効が無い)特別休暇の無期限付与に対応するため、`expires_on`が
 * 無い場合は「有効期限なし」と表示する。
 */
function leaveEventDetail(event: StoredEvent, domainLabel: string): string {
  const payload = event.payload
  const suffix = event.event_type.split('.').slice(1).join('.')

  switch (suffix) {
    case 'granted': {
      const expiry = payload.expires_on ? `有効期限 ${payload.expires_on}` : '有効期限なし'
      return `${payload.granted_days}日を付与(${expiry})`
    }
    case 'requested':
      return `対象日 ${payload.target_date} の${paidLeaveTypeLabel(payload.leave_type as PaidLeaveType)}を申請(${payload.requested_days}日)`
    case 'request_approved':
      return `${domainLabel}申請が承認されました`
    case 'request_returned':
      return `${domainLabel}申請が差し戻されました: ${payload.comment}`
    case 'request_cancelled':
      return `${domainLabel}申請を取り消しました`
    case 'used':
      return `対象日 ${payload.used_on} に${payload.used_days}日を消化`
    case 'warning_raised':
      return String(payload.message)
    default:
      return event.event_type
  }
}

export function paidLeaveEventDetail(event: StoredEvent): string {
  return leaveEventDetail(event, '有給')
}

export function specialLeaveEventDetail(event: StoredEvent): string {
  return leaveEventDetail(event, '特別休暇')
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
