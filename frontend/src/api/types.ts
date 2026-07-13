export interface User {
  id: number
  name: string
  email: string
  department: string | null
  job_title: string | null
  employment_status: string
  timezone?: string
  /** 継続勤務期間の計算に使う入社日(docs/09-usecases-paid-leave.md UC-P002)。未設定ならnull。 */
  hire_date?: string | null
  roles?: string[]
  last_login_at: string | null
}

export interface Role {
  id: number
  code: string
  name: string
}

/** UC-003: 新規作成ユーザーの既定タイムゾーンなど、システム全体の設定。 */
export interface SystemSettings {
  default_timezone: string
  /** UC-N001「勤怠未提出」警告の基準(前月分を提出すべき当月の日)。 */
  attendance_submission_deadline_day: number
  /** UC-N001「月次締め前警告」の基準(前月分を締めるべき当月の日)。 */
  attendance_month_close_deadline_day: number
}

export interface RequestType {
  id: number
  code: string
  name: string
  description: string | null
  form_schema: RequestFormFieldSchema[]
  /** UC-W001 手順2: この申請種別は添付ファイルが必須か。 */
  requires_attachment: boolean
  attachment_max_size_kb: number | null
  attachment_allowed_extensions: string[] | null
  /** UC-W001 手順4: 申請可能なロールコード。nullなら全員が申請可能。 */
  eligible_role_codes: string[] | null
  requires_backoffice_task: boolean
  backoffice_task_type: string | null
  /** UC-B001 手順4: バックオフィスタスクの初期処理部署。 */
  backoffice_department: string | null
  /** UC-B004 手順5: 会計/振込CSV出力の対象にする場合、金額として扱うform_dataのキー。 */
  export_amount_field: string | null
  /** UC-B003: task_typeごとのステータス遷移({from_status: [to_status, ...]})。nullなら制限なし。 */
  allowed_status_transitions: Record<string, string[]> | null
  is_active: boolean
}

export interface RequestFormFieldSchema {
  key: string
  label: string
  type: 'text' | 'number' | 'date'
  required?: boolean
}

export type WorkflowRequestStatus = 'draft' | 'submitted' | 'approved' | 'returned' | 'cancelled'

export interface WorkflowRequest {
  id: number
  title: string
  status: WorkflowRequestStatus
  form_data: Record<string, unknown>
  request_type?: RequestType
  applicant?: User
  approver?: User
  submitted_at: string | null
  approved_at: string | null
  returned_at: string | null
  cancelled_at: string | null
  created_at: string | null
  attachments?: Attachment[]
}

export type AttendanceDayStatus = 'not_started' | 'working' | 'on_break' | 'clocked_out'

export interface AttendanceBreak {
  id: number
  break_start_at: string | null
  break_end_at: string | null
}

export interface AttendanceDailyCalculation {
  planned_work_minutes: number
  actual_work_minutes: number
  prescribed_work_minutes: number
  non_statutory_overtime_minutes: number
  statutory_overtime_minutes: number
  late_night_minutes: number
  legal_holiday_work_minutes: number
  company_holiday_work_minutes: number
  legal_holiday_late_night_minutes: number
}

export type AttendanceDaySource = 'live' | 'manual' | 'punch'

export interface AttendanceDay {
  id: number
  user_id: number
  work_date: string
  status: AttendanceDayStatus
  source?: AttendanceDaySource
  actual_start_at: string | null
  actual_end_at: string | null
  /** その勤務日のactual_start_at/actual_end_at/breaksに適用されたUTCオフセット(分)。
   *  海外出張などで勤務日ごとに現地時刻が変わるため、社員本人の既定タイムゾーンとは別に持つ。 */
  utc_offset_minutes?: number | null
  work_type: string | null
  note: string | null
  is_locked: boolean
  breaks: AttendanceBreak[]
  calculation: AttendanceDailyCalculation | null
  planned_start_at?: string | null
  planned_end_at?: string | null
}

export type PunchType = 'clock_in' | 'break_start' | 'break_end' | 'clock_out'

export type PunchStatus = 'active' | 'corrected' | 'deleted'

/** UC-A012〜UC-A014: 打刻ログ。参考情報であり勤怠の正ではない。訂正・削除された
 *  打刻ログも行を保持したまま参照できる(status/correction_reason等)。 */
export interface AttendancePunch {
  id: number
  user_id: number
  work_date: string
  punch_type: PunchType
  punched_at: string
  source: string
  note: string | null
  status: PunchStatus
  correction_reason: string | null
  corrected_by_user_id: number | null
  corrected_at: string | null
  superseded_by_punch_id: number | null
  created_at: string | null
}

export type AttendanceMonthStatus = 'not_submitted' | 'submitted' | 'approved' | 'returned' | 'closed'

export type LegalHolidayRule = 'weekly' | 'four_weeks_four_days'

/** UC-C005: シフト制勤務者の法定休日要件(毎週1日 or 4週4日以上)を満たしていない期間。 */
export interface LegalHolidayWarning {
  rule: LegalHolidayRule
  period_start: string
  period_end: string
  legal_holiday_count: number
  required_count: number
}

export interface AttendanceMonth {
  id: number
  user_id: number
  year_month: string
  status: AttendanceMonthStatus
  approver?: User
  submitted_at: string | null
  approved_at: string | null
  returned_at: string | null
  closed_at: string | null
  snapshot: Record<string, number> | null
  legal_holiday_warnings: LegalHolidayWarning[]
}

/** UC-E001: 勤怠CSV出力の絞り込み条件。締め後(UC-A011)の月次勤怠のみが対象。 */
export interface AttendanceExportFilters {
  year_month: string
  user_id?: number
}

export type BackOfficeTaskStatus =
  | 'not_started'
  | 'in_review'
  | 'needs_fix'
  | 'processing'
  | 'ordered'
  | 'payment_scheduled'
  | 'shipped'
  | 'completed'
  | 'cancelled'

export interface BackOfficeTask {
  id: number
  source_type: string
  source_id: number
  task_type: string
  title: string
  status: BackOfficeTaskStatus
  assigned_department: string | null
  assignee?: User
  due_on: string | null
  completed_at: string | null
  created_at: string | null
}

export interface PaidLeaveGrant {
  id: number
  user_id: number
  granted_on: string
  expires_on: string
  granted_days: number
  used_days: number
  remaining_days: number
  grant_reason: string | null
}

export interface PaidLeaveGrantRuleStep {
  continuous_service_months: number
  grant_days: number
}

export type PaidLeaveType = 'full' | 'am_half' | 'pm_half' | 'hourly'

export type PaidLeaveRequestStatus = 'submitted' | 'approved' | 'returned' | 'cancelled'

export interface PaidLeaveRequest {
  id: number
  user_id: number
  user?: User
  approver?: User
  status: PaidLeaveRequestStatus
  leave_type: PaidLeaveType
  target_date: string
  hours: number | null
  requested_days: number
  reason: string | null
  submitted_at: string | null
  approved_at: string | null
  returned_at: string | null
  cancelled_at: string | null
}

export interface PaidLeaveGrantRule {
  id: number
  name: string
  work_style_id: number | null
  min_attendance_rate: number
  first_grant_after_months: number
  grant_cycle_months: number
  is_active: boolean
  steps?: PaidLeaveGrantRuleStep[]
}

export interface WorkCalendarDay {
  id: number
  date: string
  day_type: string
  is_working_day: boolean
  is_legal_holiday: boolean
  is_company_holiday: boolean
  note: string | null
}

export type WorkCalendarStatus = 'draft' | 'published'

export interface WorkCalendar {
  id: number
  name: string
  fiscal_year: number
  starts_on: string
  ends_on: string
  week_starts_on: number
  status: WorkCalendarStatus
}

export interface WorkStyle {
  id: number
  code: string
  name: string
  work_time_system: string
  prescribed_daily_minutes: number
  prescribed_weekly_minutes: number
  default_start_time: string | null
  default_end_time: string | null
  default_break_minutes: number
  calendar_id: number
  is_shift_based: boolean
  legal_holiday_rule: LegalHolidayRule
  four_week_period_start_date: string | null
  /** UC-C004: 3交代制などの連続勤務日数の警告しきい値(未設定ならチェックしない)。 */
  max_consecutive_work_days: number | null
}

export interface EmployeeShiftAssignment {
  id: number
  user_id: number
  work_date: string
  work_style_id: number
  /** UC-C004: 3交代制シフトパターンからの割当の場合のみ設定される。 */
  shift_pattern_id: number | null
  day_type: string
  is_working_day: boolean
  is_legal_holiday: boolean
  is_company_holiday: boolean
  planned_start_at: string | null
  planned_end_at: string | null
  planned_break_minutes: number
  /** UC-C004: シフトパターン割当は公開(手順6)まで下書き扱い。カレンダー一括生成は常にtrue。 */
  is_published: boolean
}

/** UC-C004 手順2: シフトパターン(日勤/準夜勤/深夜勤/公休/明け休み等)。 */
export interface ShiftPattern {
  id: number
  code: string
  name: string
  start_time: string | null
  end_time: string | null
  crosses_midnight: boolean
  break_minutes: number
  prescribed_work_minutes: number
}

/** UC-C004 手順5: シフト表公開前の警告(法定休日不足・連続勤務・月間予定時間)。 */
export interface ShiftScheduleReview {
  legal_holiday_shortages: Array<LegalHolidayWarning & { user_id: number }>
  consecutive_work_violations: Array<{
    user_id: number
    period_start: string
    period_end: string
    consecutive_days: number
    max_allowed: number
  }>
  monthly_hours_over_cap: Array<{
    user_id: number
    year_month: string
    planned_minutes: number
    statutory_cap_minutes: number
  }>
}

export interface Attachment {
  id: number
  file_name: string
  mime_type: string
  file_size: number
  uploaded_by: number
  created_at: string | null
}

export interface StoredEvent {
  id: number
  event_id: string
  aggregate_type: string
  aggregate_id: string
  version: number
  event_type: string
  payload: Record<string, unknown>
  occurred_at: string
}

export interface Paginated<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    total: number
  }
  links: {
    next: string | null
    prev: string | null
  }
}
