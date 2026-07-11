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
}

export interface RequestType {
  id: number
  code: string
  name: string
  description: string | null
  form_schema: RequestFormFieldSchema[]
  requires_backoffice_task: boolean
  backoffice_task_type: string | null
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
}

export interface EmployeeShiftAssignment {
  id: number
  user_id: number
  work_date: string
  work_style_id: number
  day_type: string
  is_working_day: boolean
  is_legal_holiday: boolean
  is_company_holiday: boolean
  planned_start_at: string | null
  planned_end_at: string | null
  planned_break_minutes: number
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
