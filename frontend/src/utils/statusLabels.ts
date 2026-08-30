import type { BadgeTone } from '../components/Badge/Badge'
import type {
  Asset,
  AssetInstallationStatus,
  AssetLendingMethod,
  AssetLendingStatus,
  AssetManagementType,
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

const CANCELLABLE_EXPENSE_CLAIM_STATUSES: ExpenseClaimStatus[] = ['draft', 'in_review', 'returned']

/** 申請者が取り消せる状態か(まだ確定していない下書き・申請中・差戻し)。 */
export function isExpenseClaimCancellable(status: ExpenseClaimStatus): boolean {
  return CANCELLABLE_EXPENSE_CLAIM_STATUSES.includes(status)
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

interface ScheduleHolidayInfo {
  is_legal_holiday?: boolean
  is_company_holiday?: boolean
  is_working_day?: boolean
}

/** 勤務予定(schedule)から、その日が休日として計画されているかどうかのバッジを返す。
 *  休日でなければnull。 */
export function attendanceScheduleHolidayLabel(schedule: ScheduleHolidayInfo | null | undefined): StatusMeta | null {
  if (!schedule) return null
  if (schedule.is_legal_holiday) return { label: '法定休日', tone: 'danger' }
  if (schedule.is_company_holiday || schedule.is_working_day === false) return { label: '所定休日', tone: 'warning' }
  return null
}

interface AttendanceDayRecordInfo {
  status: AttendanceDayStatus
  work_type: string | null | undefined
  actual_start_at?: string | null
  actual_end_at?: string | null
}

function hasActualAttendanceRecord(day: AttendanceDayRecordInfo | null | undefined): boolean {
  if (!day) return false
  return day.status !== 'not_started' || Boolean(day.actual_start_at) || Boolean(day.actual_end_at)
}

/**
 * 週次・月次・日次の各画面で共通の、勤怠日1行分の表示ラベル。休日出勤等で実績(day)が
 * 既にある場合は、その実績の状態(退勤済み等)を優先する。休日出勤という区分そのものは
 * 別途day_classification/公休名の表示で示すため、ここでは「勤務予定が休日だから」という
 * 理由だけで実績の状態バッジを隠さない。実績がまだ無い日は、勤務予定(schedule)が休日かどうか
 * を先に見せる(その日の予定が分かるようにする)。
 */
export function attendanceRowDisplayLabel(
  day: AttendanceDayRecordInfo | null | undefined,
  schedule?: ScheduleHolidayInfo | null,
): StatusMeta {
  if (!hasActualAttendanceRecord(day)) {
    const holidayMeta = attendanceScheduleHolidayLabel(schedule)
    if (holidayMeta) return holidayMeta
  }
  if (day) return attendanceDayDisplayLabel(day)
  return { label: '未入力', tone: 'neutral' }
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
  /** 有給・特別休暇の付与取消(RevokePaidLeaveGrant/RevokeSpecialLeaveGrant)。 */
  grant_revoked: { label: '取消', tone: 'danger' },
  /** 代休固有: 休日出勤実績からの自動計上(下書き)。付与payloadの形が違うため別suffixにする。 */
  grant_synced: { label: '自動計上', tone: 'success' },
  /** 代休固有: 管理者による手動付与。 */
  manually_granted: { label: '付与', tone: 'success' },
  /** 代休固有: 月次確定前の下書き付与の取消。 */
  grant_removed: { label: '計上取消', tone: 'danger' },
  /** 代休固有: 月次確定により付与が使用可能になった。 */
  grant_confirmed: { label: '確定', tone: 'success' },
  /** 代休固有: 確定後の付与取消(RevokeCompensatoryLeaveGrant相当)。paid/special leaveの
   *  grant_revokedとはpayloadのフィールド名(cancelled_by_user_id/reason)が異なる別イベント。 */
  grant_cancelled: { label: '取消', tone: 'danger' },
  /** 代休固有: 消化日の指定・取消。 */
  usage_designated: { label: '消化指定', tone: 'info' },
  usage_reversed: { label: '消化取消', tone: 'warning' },
  request_shared: { label: '連携', tone: 'neutral' },
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

export function compensatoryLeaveEventTypeLabel(eventType: string): StatusMeta {
  return leaveEventTypeLabel(eventType)
}

/**
 * 代休履歴の各イベントを、payloadの内容を使って人が読める1行に整形する。代休は
 * 有給・特別休暇と付与の仕組み(休日出勤実績からの自動計上→月次確定、または手動付与)が
 * 異なり、payloadの形も別イベントごとに大きく違うため、共通のleaveEventDetail()には
 * 寄せず専用の関数として実装する(docs/17-events.md参照)。
 */
export function compensatoryLeaveEventDetail(event: StoredEvent): string {
  const payload = event.payload
  const suffix = event.event_type.split('.').slice(1).join('.')

  switch (suffix) {
    case 'grant_synced':
      return `対象日 ${payload.work_date} の休日出勤分として${payload.granted_days}日を自動計上`
    case 'manually_granted': {
      const expiry = payload.expires_on ? `有効期限 ${payload.expires_on}` : '有効期限なし'
      return `対象日 ${payload.work_date} の休日出勤分として${payload.granted_days}日を手動付与(${expiry})`
    }
    case 'grant_confirmed': {
      const expiry = payload.expires_on ? `有効期限 ${payload.expires_on}` : '有効期限なし'
      return `代休の付与が確定しました(${expiry})`
    }
    case 'grant_removed':
      return `付与前に取り消されました: ${payload.reason}`
    case 'grant_cancelled':
      return `付与が取り消されました${payload.reason ? `(${payload.reason})` : ''}`
    case 'usage_designated':
      return `対象日 ${payload.used_on} に${payload.used_days}日の消化を指定`
    case 'usage_reversed':
      return `対象日 ${payload.used_on} の${payload.used_days}日消化を取り消し`
    case 'requested':
      return `対象日 ${payload.target_date} の${paidLeaveTypeLabel(payload.leave_type as PaidLeaveType)}を申請(${payload.requested_days}日)`
    case 'request_approved':
      return '代休申請が承認されました'
    case 'request_returned':
      return `代休申請が差し戻されました: ${payload.comment}`
    case 'request_cancelled':
      return '代休申請を取り消しました'
    case 'request_shared':
      return '申請がワークフローに連携されました'
    case 'used':
      return `対象日 ${payload.used_on} に${payload.used_days}日を消化`
    default:
      return event.event_type
  }
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

// --- 備品管理 (docs/changesets/20260830-equipment-management/spec.md) ---

const assetManagementTypeLabels: Record<AssetManagementType, string> = {
  lending: '貸出品',
  installation: '設置品',
}

export function assetManagementTypeLabel(type: AssetManagementType): string {
  return assetManagementTypeLabels[type]
}

const assetLendingMethodLabels: Record<AssetLendingMethod, string> = {
  self_service: 'セルフ貸出',
  backoffice: 'バックオフィス貸与',
  approval: '承認制',
}

export function assetLendingMethodLabel(method: AssetLendingMethod): string {
  return assetLendingMethodLabels[method]
}

const assetLendingStatusMeta: Record<AssetLendingStatus, StatusMeta> = {
  available: { label: '利用可能', tone: 'success' },
  loaned: { label: '貸出中', tone: 'info' },
  repair: { label: '修理中', tone: 'warning' },
  lost: { label: '紛失', tone: 'danger' },
  disposed: { label: '廃棄済み', tone: 'neutral' },
}

export function assetLendingStatusLabel(status: AssetLendingStatus): StatusMeta {
  return assetLendingStatusMeta[status]
}

const assetInstallationStatusMeta: Record<AssetInstallationStatus, StatusMeta> = {
  stored: { label: '保管中', tone: 'neutral' },
  installed: { label: '設置中', tone: 'success' },
  repair: { label: '修理中', tone: 'warning' },
  lost: { label: '紛失', tone: 'danger' },
  disposed: { label: '廃棄済み', tone: 'neutral' },
}

export function assetInstallationStatusLabel(status: AssetInstallationStatus): StatusMeta {
  return assetInstallationStatusMeta[status]
}

/** 一覧行の「現在の状況」1セル分の要約(spec「UI設計方針」相当: 貸出中なら誰へ、
 *  設置品なら設置場所、それ以外は状態バッジのラベルのみ)。 */
export function assetStatusSummary(asset: Pick<Asset, 'management_type' | 'lending_status' | 'installation_status' | 'current_loan' | 'current_placement'>): string {
  if (asset.management_type === 'lending') {
    if (asset.lending_status === 'loaned') {
      return `貸出中: ${asset.current_loan?.borrower?.name ?? '不明'}`
    }
    return asset.lending_status ? assetLendingStatusLabel(asset.lending_status).label : ''
  }
  if (asset.installation_status === 'installed') {
    return `設置中: ${asset.current_placement?.location_text ?? '不明'}`
  }
  return asset.installation_status ? assetInstallationStatusLabel(asset.installation_status).label : ''
}

const assetHistoryEventTypeLabels: Record<string, string> = {
  'asset.registered': '登録',
  'asset.details_updated': '詳細編集',
  'asset.deleted': '削除',
  'asset.management_type_changed': '管理区分変更',
  'asset.lending_method_changed': '貸出方式変更',
  'asset.qr_code_reissued': 'QRコード再発行',
  'asset.default_location_set': '通常配置場所設定',
  'asset.loaned': '貸与',
  'asset.returned': '返却',
  'asset.installed': '設置',
  'asset.relocated': '移設',
  'asset.removed_from_installation': '撤去',
  'asset.repair_started': '修理開始',
  'asset.repair_completed': '修理完了',
  'asset.reported_lost': '紛失登録',
  'asset.recovered_from_lost': '発見',
  'asset.disposed': '廃棄',
}

export function assetHistoryEventTypeLabel(eventType: string): string {
  return assetHistoryEventTypeLabels[eventType] ?? eventType
}
