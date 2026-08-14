import type { BadgeTone } from '../components/Badge/Badge'
import type {
  AttendanceDayStatus,
  AttendanceMonthStatus,
  BackOfficeTaskStatus,
  ExpenseClaimStatus,
  ExpensePaymentBearer,
  LegalHolidayWarning,
  PaidLeaveRequestStatus,
  PaidLeaveType,
  PunchStatus,
  PunchType,
  ShiftSwapRequestStatus,
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

const shiftSwapRequestStatusMeta: Record<ShiftSwapRequestStatus, StatusMeta> = {
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

const CANCELLABLE_WORKFLOW_REQUEST_STATUSES: WorkflowRequestStatus[] = ['draft', 'submitted', 'returned']

/** 申請者が取り消せる状態か(まだ確定していない下書き・提出中・差戻し)。 */
export function isWorkflowRequestCancellable(status: WorkflowRequestStatus): boolean {
  return CANCELLABLE_WORKFLOW_REQUEST_STATUSES.includes(status)
}

const expenseClaimStatusMeta: Record<ExpenseClaimStatus, StatusMeta> = {
  draft: { label: '下書き', tone: 'neutral' },
  in_review: { label: '申請中', tone: 'info' },
  returned: { label: '差戻し', tone: 'warning' },
  approved: { label: '承認済み', tone: 'success' },
  cancelled: { label: '取消', tone: 'danger' },
}

export function expenseClaimStatusLabel(status: ExpenseClaimStatus): StatusMeta {
  return expenseClaimStatusMeta[status]
}

/** 申請者が明細・タイトルを編集し続けられる状態か(下書き・差戻し)。 */
export function isExpenseClaimEditable(status: ExpenseClaimStatus): boolean {
  return status === 'draft' || status === 'returned'
}

/** 申請者が精算自体を削除できる状態か(まだ提出していない下書きのみ)。 */
export function isExpenseClaimDeletable(status: ExpenseClaimStatus): boolean {
  return status === 'draft'
}

const paymentBearerLabels: Record<ExpensePaymentBearer, string> = {
  employee: '個人立替',
  company: '会社支払い',
  corporate_card: '法人カード',
  customer: '先方負担',
  other: 'その他',
}

/** 「経費精算機能 設計・実装指示書」6.4: 誰が支払ったかの表示ラベル。 */
export function paymentBearerLabel(bearer: ExpensePaymentBearer): string {
  return paymentBearerLabels[bearer]
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

const PAID_LEAVE_WORK_TYPE_PREFIX = 'paid_leave_'
const SPECIAL_LEAVE_WORK_TYPE_PREFIX = 'special_leave_'

/**
 * 全休の有給・特別休暇はバックエンドが意図的に attendance_days.status を 'clocked_out' に
 * しているため(「退勤忘れ」警告の誤検知を避けるため。backend/app/Domain/Attendance/
 * Services/AttendanceCalculator.php 参照)、statusだけを見ると休暇日なのに「退勤済み」と
 * 表示されてしまう。attendance_days.work_type(paid_leave_ / special_leave_ 接頭辞, PaidLeaveType
 * ::toAttendanceWorkType() / SpecialLeaveWorkType::toAttendanceWorkType() 参照)を優先して見る。
 */
function leaveWorkTypeLabel(workType: string | null | undefined): string | null {
  if (!workType) return null
  if (workType.startsWith(PAID_LEAVE_WORK_TYPE_PREFIX)) {
    const unit = workType.slice(PAID_LEAVE_WORK_TYPE_PREFIX.length) as PaidLeaveType
    return `有給休暇(${paidLeaveTypeLabels[unit] ?? unit})`
  }
  if (workType.startsWith(SPECIAL_LEAVE_WORK_TYPE_PREFIX)) {
    const unit = workType.slice(SPECIAL_LEAVE_WORK_TYPE_PREFIX.length) as PaidLeaveType
    return `特別休暇(${paidLeaveTypeLabels[unit] ?? unit})`
  }
  return null
}

/**
 * 勤怠日1件分の表示ラベル。全休の有給・特別休暇はwork_typeから休暇種別・取得単位を
 * 表示し、それ以外はattendanceDayStatusLabel()と同じstatusベースの表示にフォールバックする。
 */
export function attendanceDayDisplayLabel(day: {
  status: AttendanceDayStatus
  work_type: string | null | undefined
}): StatusMeta {
  const leaveLabel = leaveWorkTypeLabel(day.work_type)
  if (leaveLabel) {
    return { label: leaveLabel, tone: 'info' }
  }
  return attendanceDayStatusLabel(day.status)
}

export function backOfficeTaskStatusLabel(status: BackOfficeTaskStatus): StatusMeta {
  return backOfficeTaskStatusMeta[status]
}

export function paidLeaveRequestStatusLabel(status: PaidLeaveRequestStatus): StatusMeta {
  return paidLeaveRequestStatusMeta[status]
}

export function shiftSwapRequestStatusLabel(status: ShiftSwapRequestStatus): StatusMeta {
  return shiftSwapRequestStatusMeta[status]
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

const workflowRequestHistoryActionLabels: Record<string, string> = {
  drafted: '下書き作成',
  submitted: '提出',
  approved: '承認',
  returned: '差戻し',
  cancelled: '取消',
}

export function workflowRequestHistoryActionLabel(action: string): string {
  return workflowRequestHistoryActionLabels[action] ?? action
}
