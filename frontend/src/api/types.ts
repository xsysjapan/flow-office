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
  /** ユーザーにその月の働き方(UserWorkStyleMonthlyAssignment)が無い場合のフォールバック。 */
  default_work_style_id: number | null
  default_work_style?: Pick<WorkStyle, 'id' | 'code' | 'name'> | null
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
  /** 深夜のうち所定内労働にあたる分(late_night_minutesの内訳)。 */
  regular_work_late_night_minutes: number
  /** 深夜のうち所定内残業にあたる分(late_night_minutesの内訳)。 */
  non_statutory_overtime_late_night_minutes: number
  /** 法定外残業のうち22:00〜05:00の深夜時間帯と重なる分(late_night_minutesの内訳)。 */
  statutory_overtime_late_night_minutes: number
  legal_holiday_work_minutes: number
  company_holiday_work_minutes: number
  legal_holiday_late_night_minutes: number
  /** フレックスタイム制でコアタイムを設定した日、実際の勤務がコアタイムを全てカバーしていないか。 */
  core_time_violation: boolean
  /** 区分ごとの時間(所定内労働・残業・深夜・休日労働)を手動で補正したか。実績が再編集され
   *  再計算されるとfalseに戻る。 */
  is_manually_adjusted: boolean
}

/** 日次登録後に手動補正できる区分ごとの時間。深夜(late_night_minutes)は0分の日は
 *  入力欄自体を表示しない。 */
export interface AttendanceDailyCalculationAdjustment {
  prescribed_work_minutes: number
  non_statutory_overtime_minutes: number
  statutory_overtime_minutes: number
  late_night_minutes: number
  legal_holiday_work_minutes: number
  company_holiday_work_minutes: number
  reason: string
}

/** 日次勤怠の入力画面(未入力の日)を開いた際の初期値。保存するまで正データは変更しない、
 *  あくまで入力欄への提案。 */
export interface AttendanceDayDefaults {
  source: 'punch' | 'schedule' | 'system_default' | 'none'
  actual_start_at: string | null
  actual_end_at: string | null
  breaks: Array<{ start: string; end: string | null }>
}

/** 月60時間超残業(労基法37条)の参考情報。表示のたびに都度計算され、確定値ではない。 */
export interface MonthlyOvertimeReference {
  cumulative_statutory_overtime_minutes: number
  statutory_overtime_within_60h_minutes: number
  statutory_overtime_over_60h_minutes: number
}

/** 月次確認画面(UC-A007)向けの、対象月全体の9区分の合計。提出前は都度計算した進捗の目安、
 *  提出後はattendance_months.snapshot_jsonと同じ確定値になる。 */
export interface AttendanceMonthlyCalculationTotals {
  actual_work_minutes: number
  payroll_work_minutes: number
  prescribed_work_minutes: number
  non_statutory_overtime_minutes: number
  statutory_overtime_minutes: number
  statutory_overtime_within_60h_minutes: number
  statutory_overtime_over_60h_minutes: number
  late_night_minutes: number
  regular_work_late_night_minutes: number
  non_statutory_overtime_late_night_minutes: number
  statutory_overtime_late_night_minutes: number
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
  monthly_overtime?: MonthlyOvertimeReference | null
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

/** フレックスタイム制の清算期間ダッシュボード(指示書 7.6節)。参考情報であり、表示のたびに都度計算する。 */
export interface FlexSettlementSummary {
  settlement_period_start: string
  settlement_period_end: string
  required_minutes: number
  actual_minutes: number
  remaining_minutes: number
  remaining_working_days: number
  per_day_required_minutes: number
  core_time_violation_days: number
  late_night_minutes: number
  legal_holiday_work_minutes: number
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
  /** 日次勤怠の入力画面で打刻内容を初期値として反映する際の丸め単位(5/10/15/30分)。
   *  未設定(null)は丸めない。 */
  rounding_unit_minutes: number | null
  /** 標準休憩の開始・終了時刻。勤務予定・打刻のいずれも無い日の初期値(システムの初期設定)に使う。 */
  default_break_start_time: string | null
  default_break_end_time: string | null
  calendar_id: number
  is_shift_based: boolean
  /** 会社のデフォルト働き方かどうか。常に高々1件のみtrue。 */
  is_default: boolean
  /** 初回オンボーディングで自動生成された働き方かどうか。 */
  system_generated: boolean
  legal_holiday_rule: LegalHolidayRule
  four_week_period_start_date: string | null
  /** UC-C004: 3交代制などの連続勤務日数の警告しきい値(未設定ならチェックしない)。 */
  max_consecutive_work_days: number | null
  /** フレックスタイム制(work_time_system=flex)の清算期間の起算日(1〜31)。未設定なら1日。 */
  settlement_start_day: number | null
  core_time_enabled: boolean
  core_time_start: string | null
  core_time_end: string | null
  /** 勤務可能時間帯(フレキシブルタイム)。 */
  flexible_time_start: string | null
  flexible_time_end: string | null
  /** 指示書 16.1節: 一覧画面の管理者向け集計列。GET /work-stylesでのみ設定される。 */
  applied_employee_count: number | null
  /** シフト制の働き方で使用中の勤務シフト(shift_patterns)数。シフト制でない場合はnull。 */
  active_shift_pattern_count: number | null
  configuration_warnings: string[]
  updated_at: string | null
}

/** ユーザーの月次働き方割当(docs/16-database-schema.md)。10月までは通常勤務、11月から
 *  シフト勤務のように月ごとに切り替えても過去月の履歴が残る。 */
export interface UserWorkStyleMonthlyAssignment {
  id: number
  user_id: number
  year_month: string
  work_style_id: number
  work_style?: Pick<WorkStyle, 'id' | 'code' | 'name'>
  assigned_by_user_id: number
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
  /** 休憩の開始・終了時刻。planned_break_minutes(合計分数)とは別に持つ。未設定ならnull。 */
  planned_break_start_at: string | null
  planned_break_end_at: string | null
  /** UC-C004: シフトパターン割当は公開(手順6)まで下書き扱い。カレンダー一括生成は常にtrue。 */
  is_published: boolean
  /** 個別にシフトパターンを上書きした日かどうか。ローテーションの再生成では上書きされない。 */
  is_manually_overridden: boolean
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
  /** 休憩の開始・終了時刻。日次勤怠の初期値(勤務予定の休憩を含めて表示する)に使う。 */
  break_start_time: string | null
  break_end_time: string | null
  prescribed_work_minutes: number
}

/** 指示書 8.4節: ローテーションパターンを構成する1つの順序。 */
export interface RotationPatternItem {
  sequence: number
  shift_pattern_id: number
  shift_pattern_name: string | null
  shift_pattern_code: string | null
}

/** 指示書 8.4節: 交代制勤務のローテーションパターン(A勤・B勤・C勤・休の繰り返し周期)。 */
export interface RotationPattern {
  id: number
  work_style_id: number
  name: string
  cycle_length: number
  items: RotationPatternItem[]
}

/** 指示書 8.9節: ローテーションプレビューの1日分。 */
export interface RotationPreviewDay {
  date: string
  sequence: number
  shift_pattern_id: number | null
  shift_pattern_name: string | null
  shift_pattern_code: string | null
}

/** 指示書 8.5節: 社員ごとのローテーション開始基準。 */
export interface EmployeeRotationAssignment {
  id: number
  user_id: number
  rotation_pattern_id: number
  rotation_pattern_name: string | null
  rotation_start_date: string
  rotation_start_position: number
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
