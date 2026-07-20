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
  /** 在籍期間の終端となる退社日。未設定なら在籍中。 */
  termination_date?: string | null
  roles?: string[]
  last_login_at: string | null
}

export interface Role {
  id: number
  code: string
  name: string
}

/** 初回オンボーディング(docs/06-usecases-auth.md UC-000)が必要かどうか。 */
export interface OnboardingStatus {
  needs_onboarding: boolean
  /** Microsoft 365連携設定(SSO)が既に設定済みか。falseならログイン画面はローカルパスワード
   *  フォームを表示する。 */
  sso_configured: boolean
}

/** 初回オンボーディング(SSOモード)の入力: Microsoft 365連携設定のみ。管理者になる
 *  ユーザーは事前入力せず、実際のEntra IDログイン結果で決まる。 */
export interface OnboardingSsoInput {
  m365_tenant_id: string
  m365_client_id: string
  m365_client_secret: string
  m365_redirect_uri: string
  m365_mock_enabled?: boolean
}

export interface OnboardingSsoStartResult {
  redirect_url: string
}

/** 初回オンボーディング(ローカルパスワードモード)の入力。 */
export interface OnboardingLocalInput {
  admin_name: string
  admin_email: string
  admin_password: string
}

export interface OnboardingResult {
  token: string
  user: User
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
  /** SSOログイン・MS365ユーザー同期・Graphメール送信で共有するEntra ID資格情報。 */
  m365_tenant_id: string | null
  m365_client_id: string | null
  /** クライアントシークレットは平文を返さず、設定済みかどうかのみ返す。 */
  m365_client_secret_configured: boolean
  m365_redirect_uri: string | null
  /** ローカル開発用モックOIDC(mock-oidc/)を使うかどうか。本番では有効にしない。 */
  m365_mock_enabled: boolean
  /** UC-N001: メール通知(Microsoft Graph API sendMail)の設定。有効かつm365資格情報・送信元アドレス設定済みの場合のみ送信する。 */
  notification_mail_enabled: boolean
  notification_mail_sender_address: string | null
  notification_mail_sender_name: string | null
}

/** システム設定の更新入力。クライアントシークレットのみ書き込み専用で別項目を持つ。 */
export interface UpdateSystemSettingsInput
  extends Omit<SystemSettings, 'default_work_style' | 'm365_client_secret_configured'> {
  /** 省略すると既存のシークレットを変更しない。 */
  m365_client_secret?: string
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
  id: string
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
  work_minutes: number
  prescribed_work_minutes: number
  statutory_within_overtime_minutes: number
  statutory_excess_overtime_minutes: number
  late_night_work_minutes: number
  /** 深夜のうち所定労働にあたる分(late_night_work_minutesの内訳)。 */
  late_night_prescribed_work_minutes: number
  /** 深夜のうち法定内残業にあたる分(late_night_work_minutesの内訳)。 */
  late_night_statutory_within_overtime_minutes: number
  /** 法定外残業のうち22:00〜05:00の深夜時間帯と重なる分(late_night_work_minutesの内訳)。 */
  late_night_statutory_excess_overtime_minutes: number
  legal_holiday_work_minutes: number
  prescribed_holiday_work_minutes: number
  late_night_legal_holiday_work_minutes: number
  /** フレックスタイム制でコアタイムを設定した日、実際の勤務がコアタイムを全てカバーしていないか。 */
  core_time_violation: boolean
  /** 欠勤時間(分)。attendance_leave_segmentsの区間(遅刻・早退等)の合計時間。
   *  docs/07-usecases-attendance.md「不就労時間の処理区分」参照。 */
  absence_minutes?: number
  /** 全休・半休の有給日数(全休=1.0・半休=0.5)。時間単位有給は含まない。 */
  paid_leave_days?: number
  /** 時間単位有給の消化時間(分)。 */
  paid_leave_minutes?: number
  /** 全休・半休の特別休暇日数(全休=1.0・半休=0.5)。時間単位特別休暇は含まない。 */
  special_leave_days?: number
  /** 時間単位特別休暇の消化時間(分)。 */
  special_leave_minutes?: number
  /** 区分ごとの時間(所定労働・残業・深夜・休日労働)を手動で補正したか。実績が再編集され
   *  再計算されるとfalseに戻る。 */
  is_manually_adjusted: boolean
}

/** 日次登録後に手動補正できる区分ごとの時間。 */
export interface AttendanceDailyCalculationAdjustment {
  prescribed_work_minutes: number
  statutory_within_overtime_minutes: number
  statutory_excess_overtime_minutes: number
  legal_holiday_work_minutes: number
  late_night_prescribed_work_minutes: number
  late_night_statutory_within_overtime_minutes: number
  late_night_statutory_excess_overtime_minutes: number
  late_night_legal_holiday_work_minutes: number
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
  cumulative_statutory_excess_overtime_minutes: number
  statutory_excess_overtime_within_60h_minutes: number
  statutory_excess_overtime_over_60h_minutes: number
}

/** 月次確認画面(UC-A007)向けの、対象月全体の9区分の合計。提出前は都度計算した進捗の目安、
 *  提出後はattendance_months.snapshot_jsonと同じ確定値になる。 */
export interface AttendanceMonthlyCalculationTotals {
  work_minutes: number
  payroll_work_minutes: number
  prescribed_work_minutes: number
  statutory_within_overtime_minutes: number
  statutory_excess_overtime_minutes: number
  statutory_excess_overtime_within_60h_minutes: number
  statutory_excess_overtime_over_60h_minutes: number
  late_night_work_minutes: number
  late_night_prescribed_work_minutes: number
  late_night_statutory_within_overtime_minutes: number
  late_night_statutory_excess_overtime_minutes: number
  legal_holiday_work_minutes: number
  prescribed_holiday_work_minutes: number
  late_night_legal_holiday_work_minutes: number
  /** 終日欠勤の日数(欠勤時間がその日の所定労働時間以上になった日を1日と数える)。 */
  absence_days?: number
  absence_minutes?: number
  paid_leave_days?: number
  paid_leave_minutes?: number
  special_leave_days?: number
  special_leave_minutes?: number
}

export type AttendanceDaySource = 'live' | 'manual' | 'punch'

/** 勤務予定を勤務しなかった時間帯のうち、遅刻・早退等を欠勤時間として処理した区間
 *  (docs/07-usecases-attendance.md「不就労時間の処理区分」参照。有給休暇・特別休暇
 *  (全休・半休・時間単位)は対象外で、paid_leave_requests/special_leave_requests/
 *  attendance_days.work_typeで管理する)。 */
export interface AttendanceLeaveSegment {
  id: number
  start_at: string
  end_at: string
  note: string | null
}

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
  work_location_type?: WorkLocationType | null
  note: string | null
  is_locked: boolean
  breaks: AttendanceBreak[]
  leave_segments?: AttendanceLeaveSegment[]
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
  late_night_work_minutes: number
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
  id: string
  source_type: string
  source_id: string
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

/** 特別休暇の名前付き種別マスタ(例: 誕生日休暇)。有効な種別が1件も無ければ
 *  特別休暇メニュー自体を表示しない。 */
export interface SpecialLeaveType {
  id: number
  name: string
  is_active: boolean
}

/** 特別休暇の取得単位(全休/半休/時間休)は有給と同じ概念のためPaidLeaveTypeを再利用する。 */
export interface SpecialLeaveGrant {
  id: number
  user_id: number
  special_leave_type_id: number
  special_leave_type_name?: string
  granted_on: string
  /** 有給と異なり法定の時効が無いため、失効しない付与はnullになる。 */
  expires_on: string | null
  granted_days: number
  used_days: number
  remaining_days: number
  grant_reason: string | null
}

export interface SpecialLeaveGrantRuleStep {
  continuous_service_months: number
  grant_days: number
}

export interface SpecialLeaveGrantRule {
  id: number
  special_leave_type_id: number
  special_leave_type_name?: string
  name: string
  work_style_id: number | null
  min_attendance_rate: number
  first_grant_after_months: number
  grant_cycle_months: number
  /** 失効しない自動付与ルールの場合はnull。 */
  expires_after_months: number | null
  is_active: boolean
  steps?: SpecialLeaveGrantRuleStep[]
}

export interface SpecialLeaveRequest {
  id: number
  user_id: number
  user?: User
  approver?: User
  special_leave_type_id: number
  special_leave_type_name?: string
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

export type DeviceOwnerType = 'organization_shared' | 'personal'

export type DeviceType =
  | 'android'
  | 'ios'
  | 'web_browser'
  | 'windows'
  | 'macos'
  | 'linux'
  | 'nfc_reader'
  | 'fingerprint_reader'
  | 'face_recognition_device'
  | 'access_control_device'
  | 'iot_device'
  | 'external_system'
  | 'other'

export type DeviceRoleType =
  | 'attendance_reader'
  | 'authentication_device'
  | 'access_control'
  | 'personal_operation'
  | 'admin_operation'
  | 'external_event_source'

export type DeviceScopeType =
  | 'attendance:clock'
  | 'attendance:read_current_state'
  | 'attendance:read_result'
  | 'identity:resolve'
  | 'device:heartbeat'
  | 'admin:mode'

export type DeviceStatus = 'pending_pairing' | 'active' | 'disabled' | 'revoked'

export type WorkLocationType =
  | 'office'
  | 'remote'
  | 'client_site'
  | 'business_trip'
  | 'direct_to_site'
  | 'direct_from_site'
  | 'other'

export interface Device {
  id: number
  owner_type: DeviceOwnerType
  owner_user_id: number | null
  name: string
  device_type: DeviceType
  status: DeviceStatus
  site_id: string | null
  location_name: string | null
  default_work_location_type: WorkLocationType | null
  timezone: string | null
  allowed_punch_types: string[] | null
  allow_offline: boolean
  require_location: boolean
  auto_detect_punch_type: boolean
  app_version: string | null
  last_seen_at: string | null
  paired_at: string | null
  disabled_at: string | null
  revoked_at: string | null
  deleted_at: string | null
  roles?: DeviceRoleType[]
  scopes?: DeviceScopeType[]
  created_at: string | null
}

export type AuthenticationKeyType =
  | 'nfc_uid'
  | 'employee_card_id'
  | 'qr_code'
  | 'barcode'
  | 'fingerprint_external_id'
  | 'face_recognition_external_id'
  | 'fido_credential'
  | 'bluetooth_device_id'
  | 'external_system_user_id'
  | 'custom'

export type AuthenticationKeyStatus = 'active' | 'suspended' | 'disabled'

export interface AuthenticationKey {
  id: number
  user_id: number
  key_type: AuthenticationKeyType
  display_name: string
  status: AuthenticationKeyStatus
  valid_from: string | null
  valid_until: string | null
  registered_by_user_id: number | null
  registered_at: string | null
  disabled_at: string | null
}

export type IntegrationClientType = 'api_client' | 'mcp_client' | 'ai_application' | 'external_application'

export type IntegrationStatus = 'active' | 'revoked'

export type IntegrationScopeType =
  | 'profile:self:read'
  | 'attendance:self:read'
  | 'attendance:self:clock'
  | 'attendance:self:draft'
  | 'attendance:self:update'
  | 'attendance:self:validate'
  | 'attendance:self:submit'
  | 'leave:self:read'
  | 'leave:self:create'
  | 'schedule:self:read'
  | 'report:self:import'

export interface ApplicationIntegration {
  id: number
  owner_type: 'personal' | 'organization'
  owner_user_id: number | null
  client_type: IntegrationClientType
  client_name: string
  purpose: string | null
  status: IntegrationStatus
  last_used_at: string | null
  scopes?: IntegrationScopeType[]
  created_at: string | null
}

/** UC-N001: 自分宛て通知。confirmed_atがnullなら未読。 */
export interface Notification {
  id: string
  title: string
  summary: string
  detail_url: string | null
  queued_at: string
  sent_at: string | null
  confirmed_at: string | null
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
