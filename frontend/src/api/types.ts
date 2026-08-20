export interface User {
  id: string;
  name: string;
  email: string;
  employee_number?: string | null;
  account_status?: string;
  source_type?: string;
  department: string | null;
  job_title: string | null;
  employment_status: string;
  timezone?: string;
  /** 継続勤務期間の計算に使う入社日(docs/09-usecases-paid-leave.md UC-P002)。未設定ならnull。 */
  hire_date?: string | null;
  /** 在籍期間の終端となる退社日。未設定なら在籍中。 */
  termination_date?: string | null;
  /** 本システムの利用開始日。勤怠提出フォロー等の各種フォロー通知はこの日付以降のみ送る。未設定ならnull。 */
  usage_start_date?: string | null;
  last_login_at: string | null;
  /** Microsoft 365(Entra ID)アカウントと連携済みかどうか(docs/06-usecases-auth.md UC-004)。 */
  sso_linked?: boolean;
  external_identities?: ExternalIdentity[];
  memberships?: Membership[];
  effective_features?: string[];
  effective_permissions?: string[];
  effective_access_explanation?: EffectiveAccessExplanation;
  role_assignments?: Array<{
    id: string;
    scope_type: string;
    status: string;
    role?: { name: string };
  }>;
  feature_suspensions?: Array<{
    id: number;
    reason: string;
    feature?: { name: string };
  }>;
  membership_change_sets?: Array<{
    id: string;
    effective_at: string;
    status: string;
    note?: string | null;
    failure_reason?: string | null;
    items: Array<{
      operation: "add" | "remove" | "replace" | "set_primary";
      group_type_id: number;
      from_group_id?: string | null;
      to_group_id?: string | null;
      target_group_id?: string | null;
      is_primary?: boolean;
    }>;
  }>;
  field_authorities?: Array<{
    field_key: string;
    authority_type: string;
    provider: string | null;
  }>;
}

export interface AccessSource {
  assignment_id?: string;
  type: "direct" | "group";
  group_id?: string | null;
  group_name?: string | null;
  role_name?: string;
  scope_type?: string;
  scope_group_id?: string | null;
  include_descendants?: boolean;
  starts_at?: string | null;
  ends_at?: string | null;
}

export interface EffectiveAccessExplanation {
  features: Array<{ code: string; name: string; sources: AccessSource[] }>;
  roles: Array<{ code: string; name: string; sources: AccessSource[] }>;
  permissions: Array<{
    code: string;
    description: string | null;
    sources: AccessSource[];
  }>;
}

export interface ExternalIdentity {
  id: number;
  provider: string;
  external_tenant_id: string | null;
  external_subject_id: string;
  email: string | null;
  status: string;
  last_synced_at: string | null;
}

export interface Membership {
  id: number;
  membership_kind: string;
  is_primary: boolean;
  group: {
    id: string;
    code: string;
    name: string;
    group_type: string;
    group_type_name?: string;
    group_type_id: number;
  };
}

/**
 * GET /users/search が返す軽量なユーザー情報。承認者選択(UserPicker)等、一般社員も使う
 * 用途向けで、入社日・退社日・雇用区分・ロールのような管理者向けの機微な項目は含まない
 * (それらが必要な場合はuser.view Permissionで保護されたUserを使う)。
 */
export interface UserSearchResult {
  id: string;
  name: string;
  email: string;
  department: string | null;
  job_title: string | null;
}

export interface Role {
  id: number;
  code: string;
  name: string;
}

/** 初回オンボーディング(docs/06-usecases-auth.md UC-000)が必要かどうか。 */
export interface OnboardingStatus {
  needs_onboarding: boolean;
  /** Microsoft 365連携設定(SSO)が既に設定済みか。falseならログイン画面はローカルパスワード
   *  フォームを表示する。 */
  sso_configured: boolean;
}

/** 初回オンボーディング(SSOモード)の入力: Microsoft 365連携設定のみ。管理者になる
 *  ユーザーは事前入力せず、実際のEntra IDログイン結果で決まる。 */
export interface OnboardingSsoInput {
  m365_tenant_id: string;
  m365_client_id: string;
  m365_client_secret: string;
  m365_mock_enabled?: boolean;
}

export interface OnboardingSsoStartResult {
  redirect_url: string;
}

/** 初回オンボーディング(ローカルパスワードモード)の入力。 */
export interface OnboardingLocalInput {
  admin_name: string;
  admin_email: string;
  admin_password: string;
}

export interface OnboardingResult {
  token: string;
  user: User;
}

/** UC-003: 新規作成ユーザーの既定タイムゾーンなど、システム全体の設定。 */
export interface SystemSettings {
  default_timezone: string;
  /** ユーザーにその月の働き方(UserWorkStyleMonthlyAssignment)が無い場合のフォールバック。 */
  default_work_style_id: string | null;
  default_work_style?: Pick<WorkStyle, "id" | "code" | "name"> | null;
  /** UC-N001「勤怠未提出」警告の基準(前月分を提出すべき当月の日)。 */
  attendance_submission_deadline_day: number;
  /** UC-N001「月次締め前警告」の基準(前月分を締めるべき当月の日)。 */
  attendance_month_close_deadline_day: number;
  /** SSOログイン・MS365ユーザー同期・Graphメール送信で共有するEntra ID資格情報。 */
  m365_tenant_id: string | null;
  m365_client_id: string | null;
  /** クライアントシークレットは平文を返さず、設定済みかどうかのみ返す。 */
  m365_client_secret_configured: boolean;
  /** ローカル開発用モックOIDC(mock-oidc/)を使うかどうか。本番では有効にしない。 */
  m365_mock_enabled: boolean;
  /** UC-N001: メール通知(Microsoft Graph API sendMail)の設定。有効かつm365資格情報・送信元アドレス設定済みの場合のみ送信する。 */
  notification_mail_enabled: boolean;
  notification_mail_sender_address: string | null;
  notification_mail_sender_name: string | null;
  /** UC-P003/UC-S003: 有給・特別休暇の申請に承認者による承認を必須にするか。 */
  paid_leave_requires_approval: boolean;
  special_leave_requires_approval: boolean;
  /** UC-A0xx: 振替休日申請に承認者による承認を必須にするか。falseの場合、申請すると同時に承認済みになる。 */
  shift_swap_requires_approval: boolean;
  /** 月次勤怠の提出・経費精算の申請に承認者による承認を必須にするか。 */
  attendance_requires_approval: boolean;
  expense_claim_requires_approval: boolean;
  /** 代休の消化申請に承認者による承認を必須にするか。 */
  compensatory_leave_requires_approval: boolean;
  /** 自分自身への管理系Role付与をサーバー側で拒否する。 */
  prohibit_self_privileged_role_assignment: boolean;
}

/** システム設定の更新入力。クライアントシークレットのみ書き込み専用で別項目を持つ。 */
export interface UpdateSystemSettingsInput extends Omit<
  SystemSettings,
  "default_work_style" | "m365_client_secret_configured"
> {
  /** 省略すると既存のシークレットを変更しない。 */
  m365_client_secret?: string;
}

/**
 * `GET /system-settings` のレスポンス。認証済みなら誰でも参照できる、管理者専用ではない
 * bootstrap用のシステム設定一式(system_settingsの非管理者向け部分を切り出したもの)。
 * フロントエンドの初期化時に必要な設定はここから取得する。
 */
export interface PublicSystemSettings {
  paid_leave_requires_approval: boolean;
  special_leave_requires_approval: boolean;
  shift_swap_requires_approval: boolean;
  attendance_requires_approval: boolean;
  expense_claim_requires_approval: boolean;
  compensatory_leave_requires_approval: boolean;
  default_timezone: string;
  default_work_style_id: string | null;
  default_work_style: Pick<WorkStyle, "id" | "code" | "name"> | null;
  attendance_submission_deadline_day: number;
  attendance_month_close_deadline_day: number;
}

export interface RequestType {
  id: number;
  code: string;
  name: string;
  description: string | null;
  form_schema: RequestFormFieldSchema[];
  /** UC-W001 手順2: この申請種別は添付ファイルが必須か。 */
  requires_attachment: boolean;
  attachment_max_size_kb: number | null;
  attachment_allowed_extensions: string[] | null;
  /** UC-W001 手順4: 申請可能なロールコード。nullなら全員が申請可能。 */
  eligible_role_codes: string[] | null;
  requires_backoffice_task: boolean;
  backoffice_task_type: string | null;
  /** UC-B001 手順4: バックオフィスタスクの初期処理部署。 */
  backoffice_department: string | null;
  /** UC-B004 手順5: 会計/振込CSV出力の対象にする場合、金額として扱うform_dataのキー。 */
  export_amount_field: string | null;
  /** UC-B003: task_typeごとのステータス遷移({from_status: [to_status, ...]})。nullなら制限なし。 */
  allowed_status_transitions: Record<string, string[]> | null;
  is_active: boolean;
}

export interface RequestFormFieldSchema {
  key: string;
  label: string;
  type: "text" | "number" | "date";
  required?: boolean;
}

export type WorkflowRequestStatus =
  "draft" | "submitted" | "approved" | "returned" | "cancelled";

/**
 * 統合承認画面(UC-W003/UC-W004・UC-A009・UC-X011)向け: この汎用申請が別ドメインの
 * 実データ(月次勤怠・経費精算)に紐づく申請かどうか。nullなら通常の汎用申請
 * (form_data・添付ファイルのみ)。紐づく場合、承認・差戻しは`/workflow-requests/{id}/approve`
 * ではなく対象ドメインの既存API(attendance-months/expense-claims)を呼ぶ必要がある。
 */
export type WorkflowRequestSubjectType =
  | "attendance_month"
  | "expense_claim"
  | "paid_leave_request"
  | "special_leave_request"
  | "shift_swap_request"
  | "compensatory_leave_request"
  | null;

/** 一覧(GET /workflow-requests/mine, /to-approve)に含まれる、対象ドメインの要約表示。 */
export interface AttendanceMonthSubjectSummary {
  year_month: string;
  status: AttendanceMonthStatus;
}

export interface ExpenseClaimSubjectSummary {
  title: string | null;
  status: ExpenseClaimStatus;
  total_amount: number;
}

export interface PaidLeaveRequestSubjectSummary {
  target_date: string | null;
  leave_type: PaidLeaveType;
  leave_type_label: string;
  hours: number | null;
  requested_days: number;
  reason: string | null;
}

export interface CompensatoryLeaveRequestSubjectSummary {
  target_date: string | null
  leave_type: PaidLeaveType
  leave_type_label: string
  hours: number | null
  requested_days: number
  reason: string | null
}

export interface SpecialLeaveRequestSubjectSummary {
  target_date: string | null;
  leave_type: PaidLeaveType;
  leave_type_label: string;
  special_leave_type_name: string | null;
  hours: number | null;
  requested_days: number;
  reason: string | null;
}

export interface ShiftSwapRequestSubjectSummary {
  target_date: string | null;
  substitute_date: string | null;
  reason: string | null;
}

export type WorkflowRequestSubjectSummary =
  | AttendanceMonthSubjectSummary
  | ExpenseClaimSubjectSummary
  | PaidLeaveRequestSubjectSummary
  | SpecialLeaveRequestSubjectSummary
  | ShiftSwapRequestSubjectSummary
  | CompensatoryLeaveRequestSubjectSummary;

/** GET /workflow-requests/{id}のみに含まれる、対象ドメインの実データ詳細。 */
export interface WorkflowRequestAttendanceMonthSubject {
  type: "attendance_month";
  id: string;
  user_id: string;
  year_month: string;
  status: AttendanceMonthStatus;
  submitted_at: string | null;
  approved_at: string | null;
  returned_at: string | null;
  return_comment: string | null;
  days: Array<{
    id: string;
    work_date: string;
    status: AttendanceDayStatus;
    actual_start_at: string | null;
    actual_end_at: string | null;
    breaks: Array<{
      id: number;
      break_start_at: string | null;
      break_end_at: string | null;
    }>;
  }>;
}

export interface WorkflowRequestExpenseClaimSubject {
  type: "expense_claim";
  id: string;
  employee_id: string;
  title: string | null;
  status: ExpenseClaimStatus;
  total_amount: number;
  period_from: string | null;
  period_to: string | null;
  submitted_at: string | null;
  approved_at: string | null;
  items: Array<{
    id: string;
    category_id: number;
    category_name: string | null;
    usage_date: string;
    description: string | null;
    amount: number;
    commuting_deduction_amount: number | null;
    reimbursement_amount: number | null;
    payment_bearer: ExpensePaymentBearer | null;
  }>;
}

export interface WorkflowRequestPaidLeaveRequestSubject {
  type: "paid_leave_request";
  id: string;
  user_id: string;
  status: PaidLeaveRequestStatus;
  target_date: string | null;
  leave_type: PaidLeaveType;
  leave_type_label: string;
  hours: number | null;
  requested_days: number;
  reason: string | null;
  submitted_at: string | null;
  approved_at: string | null;
  returned_at: string | null;
  cancelled_at: string | null;
  request_group_dates: string[] | null;
  used_days_last_year: number;
  pending_days_last_year: number;
  approved_days_last_year: number;
}

export interface WorkflowRequestCompensatoryLeaveRequestSubject {
  type: "compensatory_leave_request";
  id: string;
  user_id: string;
  status: PaidLeaveRequestStatus;
  target_date: string | null;
  leave_type: PaidLeaveType;
  leave_type_label: string;
  hours: number | null;
  requested_days: number;
  reason: string | null;
  submitted_at: string | null;
  approved_at: string | null;
  returned_at: string | null;
  cancelled_at: string | null;
  request_group_dates: string[] | null;
  used_days_last_year: number;
  pending_days_last_year: number;
  approved_days_last_year: number;
}

export interface WorkflowRequestSpecialLeaveRequestSubject {
  type: "special_leave_request";
  id: string;
  user_id: string;
  status: PaidLeaveRequestStatus;
  target_date: string | null;
  leave_type: PaidLeaveType;
  leave_type_label: string;
  special_leave_type_id: string;
  special_leave_type_name: string | null;
  hours: number | null;
  requested_days: number;
  reason: string | null;
  submitted_at: string | null;
  approved_at: string | null;
  returned_at: string | null;
  cancelled_at: string | null;
  request_group_dates: string[] | null;
  used_days_last_year: number;
  pending_days_last_year: number;
  approved_days_last_year: number;
}

export interface WorkflowRequestShiftSwapRequestSubject {
  type: "shift_swap_request";
  id: string;
  user_id: string;
  status: ShiftSwapRequestStatus;
  target_date: string;
  substitute_date: string;
  reason: string | null;
  return_comment: string | null;
  submitted_at: string | null;
  approved_at: string | null;
  returned_at: string | null;
  cancelled_at: string | null;
}

export type WorkflowRequestSubject =
  | WorkflowRequestAttendanceMonthSubject
  | WorkflowRequestExpenseClaimSubject
  | WorkflowRequestPaidLeaveRequestSubject
  | WorkflowRequestSpecialLeaveRequestSubject
  | WorkflowRequestShiftSwapRequestSubject
  | WorkflowRequestCompensatoryLeaveRequestSubject;

export interface WorkflowRequest {
  id: string;
  title: string;
  status: WorkflowRequestStatus;
  form_data: Record<string, unknown>;
  request_type?: RequestType;
  applicant?: User;
  approver?: User;
  submitted_at: string | null;
  approved_at: string | null;
  returned_at: string | null;
  cancelled_at: string | null;
  created_at: string | null;
  attachments?: Attachment[];
  /** 統合承認画面向け。一覧・詳細のいずれにも含まれる。未設定(古いレスポンス)はnull相当として扱う。 */
  subject_type?: WorkflowRequestSubjectType;
  /** 一覧でのみ設定される要約。詳細取得時はsubjectを使う。 */
  subject_summary?: WorkflowRequestSubjectSummary | null;
  /** GET /workflow-requests/{id}でのみ設定される、対象ドメインの実データ詳細。 */
  subject?: WorkflowRequestSubject;
}

export type AttendanceDayStatus =
  "not_started" | "working" | "on_break" | "clocked_out";

export interface AttendanceBreak {
  id: number;
  break_start_at: string | null;
  break_end_at: string | null;
}

export interface AttendanceDailyCalculation {
  prescribed_statutory_within_work_minutes?: number;
  non_prescribed_statutory_within_work_minutes?: number;
  prescribed_statutory_excess_work_minutes?: number;
  non_prescribed_statutory_excess_work_minutes?: number;
  late_night_prescribed_statutory_within_work_minutes?: number;
  late_night_non_prescribed_statutory_within_work_minutes?: number;
  late_night_prescribed_statutory_excess_work_minutes?: number;
  late_night_non_prescribed_statutory_excess_work_minutes?: number;
  planned_work_minutes: number;
  work_minutes: number;
  /** 裁量労働制のみなし労働時間(work_styles.deemed_daily_minutes)。対象外の日はnull。 */
  deemed_work_minutes?: number | null;
  /** 給与計算上の労働時間。通常はwork_minutesと同じだが、裁量労働制はdeemed_work_minutesを採用する。 */
  payroll_work_minutes?: number;
  prescribed_work_minutes: number;
  statutory_within_overtime_minutes: number;
  statutory_excess_overtime_minutes: number;
  late_night_work_minutes: number;
  /** 深夜のうち所定労働にあたる分(late_night_work_minutesの内訳)。 */
  late_night_prescribed_work_minutes: number;
  /** 深夜のうち法定内残業にあたる分(late_night_work_minutesの内訳)。 */
  late_night_statutory_within_overtime_minutes: number;
  /** 法定外残業のうち22:00〜05:00の深夜時間帯と重なる分(late_night_work_minutesの内訳)。 */
  late_night_statutory_excess_overtime_minutes: number;
  legal_holiday_work_minutes: number;
  prescribed_holiday_work_minutes: number;
  late_night_legal_holiday_work_minutes: number;
  /** 所定休日労働のうち22:00〜05:00の深夜時間帯と重なる分(late_night_work_minutesの内訳)。 */
  late_night_prescribed_holiday_work_minutes: number;
  /** フレックスタイム制でコアタイムを設定した日、実際の勤務がコアタイムを全てカバーしていないか。 */
  core_time_violation: boolean;
  /** 欠勤時間(分)。attendance_leave_segmentsの区間(遅刻・早退等)の合計時間。
   *  docs/07-usecases-attendance.md「不就労時間の処理区分」参照。 */
  absence_minutes?: number;
  /** 全休・半休の有給日数(全休=1.0・半休=0.5)。時間単位有給は含まない。 */
  paid_leave_days?: number;
  /** 時間単位有給の消化時間(分)。 */
  paid_leave_minutes?: number;
  /** 全休・半休の特別休暇日数(全休=1.0・半休=0.5)。時間単位特別休暇は含まない。 */
  special_leave_days?: number;
  /** 時間単位特別休暇の消化時間(分)。 */
  special_leave_minutes?: number;
  /** 区分ごとの時間(所定労働・残業・深夜・休日労働)を手動で補正したか。実績が再編集され
   *  再計算されるとfalseに戻る。 */
  is_manually_adjusted: boolean;
}

/** 日次登録後に手動補正できる区分ごとの時間。 */
export interface AttendanceDailyCalculationAdjustment {
  prescribed_work_minutes: number;
  statutory_within_overtime_minutes: number;
  statutory_excess_overtime_minutes: number;
  legal_holiday_work_minutes: number;
  prescribed_holiday_work_minutes: number;
  /** 給与計算上の労働時間(裁量労働制のみなし時間はここに反映される)。省略時は現在値を維持する。 */
  payroll_work_minutes?: number;
  late_night_prescribed_work_minutes: number;
  late_night_statutory_within_overtime_minutes: number;
  late_night_statutory_excess_overtime_minutes: number;
  late_night_legal_holiday_work_minutes: number;
  late_night_prescribed_holiday_work_minutes: number;
  reason: string;
}

/** 日次勤怠の入力画面(未入力の日)を開いた際の初期値。保存するまで正データは変更しない、
 *  あくまで入力欄への提案。 */
export interface AttendanceDayDefaults {
  source: "punch" | "schedule" | "system_default" | "none";
  actual_start_at: string | null;
  actual_end_at: string | null;
  breaks: Array<{ start: string; end: string | null }>;
}

/** 月60時間超残業(労基法37条)の参考情報。表示のたびに都度計算され、確定値ではない。 */
export interface MonthlyOvertimeReference {
  cumulative_statutory_excess_overtime_minutes: number;
  statutory_excess_overtime_within_60h_minutes: number;
  statutory_excess_overtime_over_60h_minutes: number;
}

/** 特別休暇の種類ごとの内訳(special_leave_type_id別)。daysは全休・半休相当の合計
 *  (全休=1.0・半休=0.5)、minutesは時間単位特別休暇の消化時間(分)。全種類の合計は
 *  AttendanceMonthlyCalculationTotals.special_leave_days/special_leave_minutesと一致する。 */
export interface SpecialLeaveBreakdownEntry {
  special_leave_type_id: string;
  special_leave_type_name: string;
  days: number;
  minutes: number;
}

/** 月次確認画面(UC-A007)向けの、対象月全体の10区分の合計。提出前は都度計算した進捗の目安、
 *  提出後はattendance_months.snapshot_jsonと同じ確定値になる。 */
export interface AttendanceMonthlyCalculationTotals {
  work_minutes: number;
  payroll_work_minutes: number;
  prescribed_work_minutes: number;
  prescribed_statutory_within_work_minutes?: number;
  non_prescribed_statutory_within_work_minutes?: number;
  prescribed_statutory_excess_work_minutes?: number;
  non_prescribed_statutory_excess_work_minutes?: number;
  statutory_within_overtime_minutes: number;
  statutory_excess_overtime_minutes: number;
  statutory_excess_overtime_within_60h_minutes: number;
  statutory_excess_overtime_over_60h_minutes: number;
  /** 週40時間(労基法32条)超残業の月内全週合計。日8時間超・月60時間超とは別区分。 */
  weekly_statutory_excess_overtime_minutes: number;
  late_night_work_minutes: number;
  late_night_prescribed_work_minutes: number;
  late_night_statutory_within_overtime_minutes: number;
  late_night_statutory_excess_overtime_minutes: number;
  late_night_prescribed_statutory_within_work_minutes?: number;
  late_night_non_prescribed_statutory_within_work_minutes?: number;
  late_night_prescribed_statutory_excess_work_minutes?: number;
  late_night_non_prescribed_statutory_excess_work_minutes?: number;
  legal_holiday_work_minutes: number;
  prescribed_holiday_work_minutes: number;
  late_night_legal_holiday_work_minutes: number;
  late_night_prescribed_holiday_work_minutes: number;
  /** 終日欠勤の日数(欠勤時間がその日の所定労働時間以上になった日を1日と数える)。 */
  absence_days?: number;
  absence_minutes?: number;
  paid_leave_days?: number;
  paid_leave_minutes?: number;
  special_leave_days?: number;
  special_leave_minutes?: number;
}

export type AttendanceDaySource = "live" | "manual" | "punch";

/** 勤務予定を勤務しなかった時間帯のうち、遅刻・早退等を欠勤時間として処理した区間
 *  (docs/07-usecases-attendance.md「不就労時間の処理区分」参照。有給休暇・特別休暇
 *  (全休・半休・時間単位)は対象外で、paid_leave_requests/special_leave_requests/
 *  attendance_days.work_typeで管理する)。 */
export interface AttendanceLeaveSegment {
  id: number;
  start_at: string;
  end_at: string;
  note: string | null;
}

export interface AttendanceDay {
  id: string;
  user_id: string;
  work_date: string;
  status: AttendanceDayStatus;
  source?: AttendanceDaySource;
  actual_start_at: string | null;
  actual_end_at: string | null;
  /** その勤務日のactual_start_at/actual_end_at/breaksに適用されたUTCオフセット(分)。
   *  海外出張などで勤務日ごとに現地時刻が変わるため、社員本人の既定タイムゾーンとは別に持つ。 */
  utc_offset_minutes?: number | null;
  work_type: string | null;
  work_location_type?: WorkLocationType | null;
  /** その勤務日の区分(労働日/所定休日/法定休日)。AttendanceCalculatorが日次計算のたびに
   *  判定・保存する派生値。未計算(旧データ)の日はnull。 */
  day_classification?:
    "working_day" | "prescribed_holiday" | "legal_holiday" | null;
  note: string | null;
  is_locked: boolean;
  breaks: AttendanceBreak[];
  leave_segments?: AttendanceLeaveSegment[];
  calculation: AttendanceDailyCalculation | null;
  monthly_overtime?: MonthlyOvertimeReference | null;
  planned_start_at?: string | null;
  planned_end_at?: string | null;
  /** その日の特別休暇消化の内訳(種類ごと)。通常は1件だが、失効日の異なる複数grantに
   *  またがる場合は複数件になりうる(週次画面での種類別集計に使う。AttendanceDayResource参照)。 */
  special_leave_usages?: Array<{
    special_leave_type_id: string;
    special_leave_type_name: string;
    usage_type: "full" | "am_half" | "pm_half" | "hourly";
    used_days: number;
    used_minutes: number | null;
  }>;
}

export type PunchType = "clock_in" | "break_start" | "break_end" | "clock_out";

export type PunchStatus = "active" | "corrected" | "deleted";

/** UC-A012〜UC-A014: 打刻ログ。参考情報であり勤怠の正ではない。訂正・削除された
 *  打刻ログも行を保持したまま参照できる(status/correction_reason等)。 */
export interface AttendancePunch {
  id: string;
  user_id: string;
  work_date: string;
  punch_type: PunchType;
  punched_at: string;
  source: string;
  note: string | null;
  status: PunchStatus;
  correction_reason: string | null;
  corrected_by_user_id: string | null;
  corrected_at: string | null;
  superseded_by_punch_id: string | null;
  created_at: string | null;
}

export type AttendanceMonthStatus =
  "not_submitted" | "submitted" | "approved" | "returned" | "closed";

export type LegalHolidayRule = "weekly" | "four_weeks_four_days";

/**
 * 打刻の丸め方向。nearest=四捨五入、shorten=勤務時間が短くなる方向(始業・休憩終了は
 * 繰り上げ、終業・休憩開始は繰り下げ)、lengthen=勤務時間が長くなる方向(逆)。
 */
export type RoundingMode = "nearest" | "shorten" | "lengthen";

/** UC-C005: シフト制勤務者の法定休日要件(毎週1日 or 4週4日以上)を満たしていない期間。 */
export interface LegalHolidayWarning {
  rule: LegalHolidayRule;
  period_start: string;
  period_end: string;
  legal_holiday_count: number;
  required_count: number;
}

export interface AttendanceMonth {
  id: string;
  user_id: string;
  user?: User;
  year_month: string;
  status: AttendanceMonthStatus;
  approver?: User;
  submitted_at: string | null;
  approved_at: string | null;
  returned_at: string | null;
  return_comment: string | null;
  closed_at: string | null;
  snapshot: Record<string, number> | null;
  legal_holiday_warnings: LegalHolidayWarning[];
}

/** フレックスタイム制の清算期間ダッシュボード(指示書 7.6節)。参考情報であり、表示のたびに都度計算する。 */
export interface FlexSettlementSummary {
  settlement_period_start: string;
  settlement_period_end: string;
  required_minutes: number;
  actual_minutes: number;
  remaining_minutes: number;
  remaining_working_days: number;
  per_day_required_minutes: number;
  core_time_violation_days: number;
  late_night_work_minutes: number;
  legal_holiday_work_minutes: number;
}

/** UC-E001: 勤怠CSV出力フォーマット。省略時は`generic`。 */
export type AttendanceExportFormat =
  "generic" | "generic_tsv" | "generic_sjis" | "moneyforward" | "freee";

/** UC-E001: 勤怠CSV出力の絞り込み条件。締め後(UC-A011)の月次勤怠のみが対象。 */
export interface AttendanceExportFilters {
  /** 複数月をまとめて出力できる(1件以上必須)。 */
  year_month: string[];
  /** 未指定(空配列)の場合は全社員が対象。 */
  user_id?: string[];
  format?: AttendanceExportFormat;
}

export type BackOfficeTaskStatus =
  | "not_started"
  | "in_review"
  | "needs_fix"
  | "processing"
  | "ordered"
  | "payment_scheduled"
  | "shipped"
  | "completed"
  | "cancelled";

export interface BackOfficeTask {
  id: string;
  source_type: string;
  source_id: string;
  task_type: string;
  title: string;
  status: BackOfficeTaskStatus;
  assigned_department: string | null;
  assignee?: User;
  due_on: string | null;
  completed_at: string | null;
  created_at: string | null;
}

export type LeaveGrantStatus = "active" | "revoked";

export interface PaidLeaveGrant {
  id: string;
  user_id: string;
  granted_on: string;
  expires_on: string;
  granted_days: number;
  used_days: number;
  remaining_days: number;
  grant_reason: string | null;
  status: LeaveGrantStatus;
  revoked_at: string | null;
  revoked_by_user_id: string | null;
  revoke_reason: string | null;
}

/** 有給の消化記録(usage行)。1件の承認済み申請が複数の付与(grant)にまたがって消化された
 *  場合、同一のrequest_idを持つ複数行として返る。管理者向けの取消操作はrequest単位で行う。 */
export interface PaidLeaveUsage {
  id: string;
  user_id: string;
  used_on: string;
  used_days: number;
  used_minutes: number | null;
  usage_type: PaidLeaveType;
  is_confirmed: boolean;
  paid_leave_grant_id: string;
  paid_leave_request_id: string | null;
  request_status: PaidLeaveRequestStatus | null;
}

/** 特別休暇の消化記録(usage行)。PaidLeaveUsageと同じ形。 */
export interface SpecialLeaveUsage {
  id: string;
  user_id: string;
  used_on: string;
  used_days: number;
  used_minutes: number | null;
  usage_type: PaidLeaveType;
  is_confirmed: boolean;
  special_leave_grant_id: string;
  special_leave_request_id: string | null;
  request_status: PaidLeaveRequestStatus | null;
}

/** 代休の消化記録(usage行)。PaidLeaveUsageと同じ形。 */
export interface CompensatoryLeaveUsage {
  id: string;
  user_id: string;
  used_on: string;
  used_days: number;
  used_minutes: number | null;
  usage_type: PaidLeaveType;
  is_confirmed: boolean;
  compensatory_leave_grant_id: string;
  compensatory_leave_request_id: string | null;
  request_status: PaidLeaveRequestStatus | null;
}

export interface GroupOption {
  id: string;
  name: string;
}

export interface GroupMember {
  user_id: string;
  name: string;
  email: string;
  membership_kind: string;
  is_primary: boolean;
}

export interface PaidLeaveGrantRuleStep {
  continuous_service_months: number;
  grant_days: number;
}

/**
 * 代休の残数(付与)。休日出勤の勤怠実績から自動導出されるため付与のCRUDは無く、
 * `granted_minutes`/`remaining_minutes`が入る時間単位のGrantも存在する
 * (backend/app/Http/Resources/CompensatoryLeaveGrantResource.php参照)。
 */
export interface CompensatoryLeaveGrant {
  id: string;
  user_id: string;
  source: "attendance" | "manual";
  attendance_day_id: string | null;
  work_date: string;
  status: "draft" | "confirmed" | "cancelled";
  granted_days: number;
  granted_minutes: number | null;
  used_days: number;
  used_minutes: number | null;
  remaining_days: number;
  remaining_minutes: number | null;
  confirmed_at: string | null;
  expires_on: string | null;
  grant_reason: string | null;
}

/** 代休の消化申請。ステータス・取得単位は有給申請と同じ概念のため型を再利用する。 */
export interface CompensatoryLeaveRequest {
  id: string;
  user_id: string;
  user?: User;
  approver?: User;
  status: PaidLeaveRequestStatus;
  leave_type: PaidLeaveType;
  target_date: string;
  hours: number | null;
  requested_days: number;
  requested_minutes: number | null;
  reason: string | null;
  submitted_at: string | null;
  approved_at: string | null;
  returned_at: string | null;
  cancelled_at: string | null;
}

export type PaidLeaveType = "full" | "am_half" | "pm_half" | "hourly";

export type PaidLeaveRequestStatus =
  "submitted" | "approved" | "returned" | "cancelled";

export interface PaidLeaveRequest {
  id: string;
  user_id: string;
  user?: User;
  approver?: User;
  status: PaidLeaveRequestStatus;
  leave_type: PaidLeaveType;
  target_date: string;
  hours: number | null;
  requested_days: number;
  reason: string | null;
  submitted_at: string | null;
  approved_at: string | null;
  returned_at: string | null;
  cancelled_at: string | null;
}

export interface PaidLeaveGrantRule {
  id: number;
  name: string;
  work_style_id: string | null;
  min_attendance_rate: number;
  first_grant_after_months: number;
  grant_cycle_months: number;
  is_active: boolean;
  steps?: PaidLeaveGrantRuleStep[];
}

/** 特別休暇の名前付き種別マスタ(例: 誕生日休暇)。有効な種別が1件も無ければ
 *  特別休暇メニュー自体を表示しない。 */
export interface SpecialLeaveType {
  id: number;
  name: string;
  is_active: boolean;
  /** falseの場合、事前の付与(残数)が無くても申請できる(忌引・代休等)。 */
  requires_grant: boolean;
}

/** 特別休暇の取得単位(全休/半休/時間休)は有給と同じ概念のためPaidLeaveTypeを再利用する。 */
export interface SpecialLeaveGrant {
  id: string;
  user_id: string;
  special_leave_type_id: number;
  special_leave_type_name?: string;
  granted_on: string;
  /** 有給と異なり法定の時効が無いため、失効しない付与はnullになる。 */
  expires_on: string | null;
  granted_days: number;
  used_days: number;
  remaining_days: number;
  grant_reason: string | null;
  status: LeaveGrantStatus;
  revoked_at: string | null;
  revoked_by_user_id: string | null;
  revoke_reason: string | null;
}

export interface SpecialLeaveGrantRuleStep {
  continuous_service_months: number;
  grant_days: number;
}

export interface SpecialLeaveGrantRule {
  id: number;
  special_leave_type_id: number;
  special_leave_type_name?: string;
  name: string;
  work_style_id: string | null;
  min_attendance_rate: number;
  first_grant_after_months: number;
  grant_cycle_months: number;
  /** 失効しない自動付与ルールの場合はnull。 */
  expires_after_months: number | null;
  is_active: boolean;
  steps?: SpecialLeaveGrantRuleStep[];
}

export interface SpecialLeaveRequest {
  id: string;
  user_id: string;
  user?: User;
  approver?: User;
  special_leave_type_id: number;
  special_leave_type_name?: string;
  status: PaidLeaveRequestStatus;
  leave_type: PaidLeaveType;
  target_date: string;
  hours: number | null;
  requested_days: number;
  reason: string | null;
  submitted_at: string | null;
  approved_at: string | null;
  returned_at: string | null;
  cancelled_at: string | null;
}

export type ShiftSwapRequestStatus =
  "submitted" | "approved" | "returned" | "cancelled";

/** 振替休日申請。休日(法定休日/所定休日)の勤務日を、別の日(振替先日)の休みと入れ替える申請。 */
export interface ShiftSwapRequest {
  id: string;
  user_id: string;
  user?: User;
  approver?: User;
  status: ShiftSwapRequestStatus;
  /** 振替対象の休日(この日に出勤する)。 */
  target_date: string;
  /** 振替先の休日(代わりに休む日)。 */
  substitute_date: string;
  reason: string | null;
  return_comment: string | null;
  submitted_at: string | null;
  approved_at: string | null;
  returned_at: string | null;
  cancelled_at: string | null;
  created_at?: string | null;
}

export interface WorkCalendarDay {
  id: number;
  date: string;
  day_type: string;
  is_working_day: boolean;
  is_legal_holiday: boolean;
  is_company_holiday: boolean;
  is_public_holiday: boolean;
  public_holiday_name: string | null;
  schedule_state: "WORK" | "OFF";
  note: string | null;
}

// 会社カレンダー本体(docs/08-usecases-calendar-shift.md UC-C009)。年度依存フィールドは
// WorkCalendarYear側にある(旧: 本体が直接保持していたが分離した)。
export type WorkCalendarStatus = "active" | "archived";

/** ISO曜日番号(文字列キー): "1"=月曜〜"7"=日曜。 */
export type WeekdayHolidayPatternDayType = "working" | "company_holiday" | "legal_holiday";
export type WeekdayHolidayPattern = Record<"1" | "2" | "3" | "4" | "5" | "6" | "7", WeekdayHolidayPatternDayType>;

export interface WorkCalendar {
  id: string;
  name: string;
  week_starts_on: number;
  fiscal_year_start_month: number;
  fiscal_year_start_day: number;
  holiday_calendar_source_id: string | null;
  is_default: boolean;
  status: WorkCalendarStatus;
  weekday_holiday_pattern: WeekdayHolidayPattern;
  /** falseの場合、日別編集画面で個別の勤務区分・祝日区分を変更できない(常に曜日ごとの休日設定から導出される)。 */
  allow_daily_holiday_override: boolean;
}

export type WorkCalendarYearStatus = "draft" | "published" | "archived";

export interface WorkCalendarYear {
  id: string;
  company_calendar_id: string;
  fiscal_year: number;
  starts_on: string;
  ends_on: string;
  status: WorkCalendarYearStatus;
  generated_from: "manual" | "standard_template";
  published_at: string | null;
  published_by_user_id: string | null;
}

export interface WorkStyle {
  id: string;
  code: string;
  name: string;
  work_time_system: string;
  prescribed_daily_minutes: number;
  prescribed_weekly_minutes: number;
  /** 実績をAttendanceDayへ帰属させる日の境界。work_dateは作業日、midnightは暦日、customは指定時刻。 */
  workday_boundary_type?: "work_date" | "midnight" | "custom";
  workday_boundary_time?: string | null;
  /** 裁量労働制(work_time_system='discretionary')のみなし労働時間(分/日)。
   *  対象日の給与計算上の労働時間(payroll_work_minutes)に採用される。対象外の勤務形態ではnull。 */
  deemed_daily_minutes: number | null;
  default_start_time: string | null;
  default_end_time: string | null;
  default_break_minutes: number;
  /** 日次勤怠の入力画面で打刻内容を初期値として反映する際の丸め単位(5/10/15/30分)。
   *  未設定(null)は丸めない。 */
  rounding_unit_minutes: number | null;
  /** 丸め方向。未設定(null)は'nearest'(四捨五入)として扱う。 */
  rounding_mode: RoundingMode | null;
  /** 標準休憩の開始・終了時刻。勤務予定・打刻のいずれも無い日の初期値(システムの初期設定)に使う。 */
  default_break_start_time: string | null;
  default_break_end_time: string | null;
  /** 退勤時、休憩が1件も記録されていない日に標準休憩(default_break_start_time〜
   *  default_break_end_time)を自動でattendance_breaksへ補完するかどうか。 */
  auto_break_enabled: boolean;
  company_calendar_id: string;
  is_shift_based: boolean;
  /** 会社のデフォルト働き方かどうか。常に高々1件のみtrue。 */
  is_default: boolean;
  /** 初回オンボーディングで自動生成された働き方かどうか。 */
  system_generated: boolean;
  legal_holiday_rule: LegalHolidayRule;
  four_week_period_start_date: string | null;
  /** UC-C004: 3交代制などの連続勤務日数の警告しきい値(未設定ならチェックしない)。 */
  max_consecutive_work_days: number | null;
  /** フレックスタイム制(work_time_system=flex)の清算期間の起算日(1〜31)。未設定なら1日。 */
  settlement_start_day: number | null;
  core_time_enabled: boolean;
  core_time_start: string | null;
  core_time_end: string | null;
  /** 勤務可能時間帯(フレキシブルタイム)。 */
  flexible_time_start: string | null;
  flexible_time_end: string | null;
  /** 指示書 16.1節: 一覧画面の管理者向け集計列。GET /work-stylesでのみ設定される。 */
  applied_employee_count: number | null;
  /** シフト制の働き方で使用中の勤務シフト(shift_patterns)数。シフト制でない場合はnull。 */
  active_shift_pattern_count: number | null;
  configuration_warnings: string[];
  updated_at: string | null;
}

/** ユーザーの月次働き方割当(docs/16-database-schema.md)。10月までは通常勤務、11月から
 *  シフト勤務のように月ごとに切り替えても過去月の履歴が残る。 */
export interface UserWorkStyleMonthlyAssignment {
  id: string;
  user_id: string;
  year_month: string;
  work_style_id: string;
  work_style?: Pick<WorkStyle, "id" | "code" | "name">;
  assigned_by_user_id: string;
}

export interface EmployeeShiftAssignment {
  id: string | null;
  user_id: string;
  work_date: string;
  work_style_id: string | null;
  /** UC-C004: 3交代制シフトパターンからの割当の場合のみ設定される。 */
  shift_pattern_id: string | null;
  day_type: string;
  is_working_day: boolean;
  is_legal_holiday: boolean;
  is_company_holiday: boolean;
  is_public_holiday?: boolean;
  public_holiday_name?: string | null;
  schedule_state?: "WORK" | "OFF" | "LEAVE";
  planned_start_at: string | null;
  planned_end_at: string | null;
  planned_break_minutes: number;
  /** 休憩の開始・終了時刻。planned_break_minutes(合計分数)とは別に持つ。未設定ならnull。 */
  planned_break_start_at: string | null;
  planned_break_end_at: string | null;
  /** UC-C004: シフトパターン割当は公開(手順6)まで下書き扱い。カレンダー一括生成は常にtrue。 */
  is_published: boolean;
  /** 個別にシフトパターンを上書きした日かどうか。ローテーションの再生成では上書きされない。 */
  is_manually_overridden: boolean;
  /** company_calendar is the base schedule; employee_calendar_entry is a persisted override. */
  schedule_source?: "company_calendar" | "employee_calendar_entry" | "provisional";
  provisional?: boolean;
}

/** UC-C004 手順2: シフトパターン(日勤/準夜勤/深夜勤/公休/明け休み等)。 */
export interface ShiftPattern {
  id: string;
  code: string;
  name: string;
  start_time: string | null;
  end_time: string | null;
  crosses_midnight: boolean;
  break_minutes: number;
  /** 休憩の開始・終了時刻。日次勤怠の初期値(勤務予定の休憩を含めて表示する)に使う。 */
  break_start_time: string | null;
  break_end_time: string | null;
  prescribed_work_minutes: number;
}

/** 指示書 8.4節: ローテーションパターンを構成する1つの順序。 */
export interface RotationPatternItem {
  sequence: number;
  shift_pattern_id: string;
  shift_pattern_name: string | null;
  shift_pattern_code: string | null;
}

/** 指示書 8.4節: 交代制勤務のローテーションパターン(A勤・B勤・C勤・休の繰り返し周期)。 */
export interface RotationPattern {
  id: string;
  work_style_id: string;
  name: string;
  cycle_length: number;
  items: RotationPatternItem[];
}

/** 指示書 8.9節: ローテーションプレビューの1日分。 */
export interface RotationPreviewDay {
  date: string;
  sequence: number;
  shift_pattern_id: string | null;
  shift_pattern_name: string | null;
  shift_pattern_code: string | null;
}

/** 指示書 8.5節: 社員ごとのローテーション開始基準。 */
export interface EmployeeRotationAssignment {
  id: string;
  user_id: string;
  rotation_pattern_id: string;
  rotation_pattern_name: string | null;
  rotation_start_date: string;
  rotation_start_position: number;
}

/** UC-C004 手順5: シフト表公開前の警告(法定休日不足・連続勤務・月間予定時間)。 */
export interface ShiftScheduleReview {
  legal_holiday_shortages: Array<LegalHolidayWarning & { user_id: string }>;
  consecutive_work_violations: Array<{
    user_id: string;
    period_start: string;
    period_end: string;
    consecutive_days: number;
    max_allowed: number;
  }>;
  monthly_hours_over_cap: Array<{
    user_id: string;
    year_month: string;
    planned_minutes: number;
    statutory_cap_minutes: number;
  }>;
}

export interface Attachment {
  id: string;
  file_name: string;
  mime_type: string;
  file_size: number;
  uploaded_by: string;
  created_at: string | null;
}

export interface StoredEvent {
  id: string;
  event_id: string;
  aggregate_type: string;
  aggregate_id: string;
  version: number;
  event_type: string;
  payload: Record<string, unknown>;
  occurred_at: string;
}

export interface WorkflowRequestHistoryEntry {
  id: number;
  action: "drafted" | "submitted" | "approved" | "returned" | "cancelled";
  actor_user_id: string | null;
  comment: string | null;
  occurred_at: string;
}

export type DeviceOwnerType = "organization_shared" | "personal";

export type DeviceType =
  | "android"
  | "ios"
  | "web_browser"
  | "windows"
  | "macos"
  | "linux"
  | "nfc_reader"
  | "fingerprint_reader"
  | "face_recognition_device"
  | "access_control_device"
  | "iot_device"
  | "external_system"
  | "other";

export type DeviceRoleType =
  | "attendance_reader"
  | "authentication_device"
  | "access_control"
  | "personal_operation"
  | "admin_operation"
  | "external_event_source";

export type DeviceScopeType =
  | "attendance:clock"
  | "attendance:read_current_state"
  | "attendance:read_result"
  | "identity:resolve"
  | "device:heartbeat"
  | "admin:mode";

export type DeviceStatus =
  "pending_pairing" | "active" | "disabled" | "revoked";

export type WorkLocationType =
  | "office"
  | "remote"
  | "client_site"
  | "business_trip"
  | "direct_to_site"
  | "direct_from_site"
  | "other";

export interface Device {
  id: string;
  owner_type: DeviceOwnerType;
  owner_user_id: string | null;
  name: string;
  device_type: DeviceType;
  status: DeviceStatus;
  site_id: string | null;
  location_name: string | null;
  default_work_location_type: WorkLocationType | null;
  timezone: string | null;
  allowed_punch_types: string[] | null;
  allow_offline: boolean;
  require_location: boolean;
  auto_detect_punch_type: boolean;
  app_version: string | null;
  last_seen_at: string | null;
  paired_at: string | null;
  disabled_at: string | null;
  revoked_at: string | null;
  deleted_at: string | null;
  roles?: DeviceRoleType[];
  scopes?: DeviceScopeType[];
  created_at: string | null;
}

export type AuthenticationKeyType =
  | "nfc_uid"
  | "employee_card_id"
  | "qr_code"
  | "barcode"
  | "fingerprint_external_id"
  | "face_recognition_external_id"
  | "fido_credential"
  | "bluetooth_device_id"
  | "external_system_user_id"
  | "custom";

export type AuthenticationKeyStatus = "active" | "suspended" | "disabled";

export interface AuthenticationKey {
  id: string;
  user_id: string;
  key_type: AuthenticationKeyType;
  display_name: string;
  status: AuthenticationKeyStatus;
  valid_from: string | null;
  valid_until: string | null;
  registered_by_user_id: string | null;
  registered_at: string | null;
  disabled_at: string | null;
}

export type IntegrationClientType =
  "api_client" | "mcp_client" | "ai_application" | "external_application";

export type IntegrationStatus = "active" | "revoked";

export type IntegrationScopeType =
  | "profile:self:read"
  | "attendance:self:read"
  | "attendance:self:clock"
  | "attendance:self:draft"
  | "attendance:self:update"
  | "attendance:self:validate"
  | "attendance:self:submit"
  | "leave:self:read"
  | "leave:self:create"
  | "schedule:self:read"
  | "report:self:import";

export interface ApplicationIntegration {
  id: string;
  owner_type: "personal" | "organization";
  owner_user_id: string | null;
  client_type: IntegrationClientType;
  client_name: string;
  purpose: string | null;
  status: IntegrationStatus;
  last_used_at: string | null;
  scopes?: IntegrationScopeType[];
  created_at: string | null;
}

/** UC-N001: 自分宛て通知。confirmed_atがnullなら未読。 */
export interface Notification {
  id: string;
  title: string;
  summary: string;
  detail_url: string | null;
  queued_at: string;
  sent_at: string | null;
  confirmed_at: string | null;
}

/** docs/30-usecases-expense.md UC-X001: 経費区分マスタ。区分ごとの証憑要件・承認省略しきい値は
 *  すべてマスタ設定で、区分追加のためにコードを変更しない。 */
export type ExpenseEvidenceType =
  "fact_reference_available" | "receipt_required" | "receipt_optional";

/** UC-X001手順3/UC-X004: `batch`は交通費専用のまとめ入力ツール(表形式・移動経路・
 *  テンプレート)、`single`は区分専用の1件入力フォーム(続けて何度でも入力できる)。 */
export type ExpenseCategoryEntryMode = "batch" | "single";

/** 経費区分固有の入力項目定義(field_definitions)。expense_items.attributesに保存できる
 *  キーをここで定義したものだけに限定する(「経費精算機能 設計・実装指示書」7.2)。 */
export interface ExpenseCategoryFieldDefinition {
  key: string;
  label: string;
  type: "text" | "number" | "date" | "select" | "boolean";
  required?: boolean;
  options?: Array<{ value: string; label: string }>;
}

export interface ExpenseCategory {
  id: number;
  code: string;
  name: string;
  description: string | null;
  evidence_type_default: ExpenseEvidenceType;
  entry_mode: ExpenseCategoryEntryMode;
  /** 区分固有の追加入力項目。未設定(null)ならattributesの内容を制限しない。 */
  field_definitions: ExpenseCategoryFieldDefinition[] | null;
  /** レシート添付が必須となる金額しきい値(円)。未設定(null)なら金額によらずevidence_type_defaultに従う。 */
  receipt_required_threshold: number | null;
  /** UC-X011 手順5: この金額以下の明細は承認を1段階省略できる。未設定(null)なら省略なし。 */
  approval_skip_threshold: number | null;
  is_active: boolean;
}

/** 「経費精算機能 設計・実装指示書」9.4: personal(本人のみ編集)・company(経理・管理者のみ
 *  編集、全社員が利用可)・system(経理・管理者のみ編集、標準プリセット)は`visibility`の違いの
 *  みで表現し、テーブル・振る舞いを分けない。 */
export type ExpenseEntryPresetVisibility = "personal" | "company" | "system";

/** 表示上の分類。適用処理自体はdefinitionの件数に従うため、内部の挙動は共通。 */
export type ExpenseEntryPresetType = "single_item" | "multiple_items";

/** プリセットが生成する経費明細1件分の下書き定義。descriptionは一覧表示・交通費の
 *  まとめ入力(表形式)でそのまま使う自由記述の1行テキスト。payee〜destinationは
 *  単票入力フォーム(SingleExpenseItemForm)の入力補助欄(取引先・内容・参加者情報・
 *  出発地/到着地)にそのまま反映するための構造化データで、fieldSetに応じて使う項目が
 *  変わる(区分・テーブル定義は変えず、JSON列であるdefinitionの中身だけを拡張している)。 */
export interface ExpenseEntryPresetDefinitionItem {
  category_id: number;
  description?: string | null;
  amount?: number | null;
  payment_bearer?: ExpensePaymentBearer | null;
  attributes?: Record<string, unknown> | null;
  payee?: string | null;
  content?: string | null;
  participants?: string | null;
  participant_count?: number | null;
  departure?: string | null;
  destination?: string | null;
}

export interface ExpenseEntryPreset {
  id: number;
  visibility: ExpenseEntryPresetVisibility;
  /** personal/company/systemの違いに関わらず、company/systemはnull。 */
  owner_user_id: string | null;
  name: string;
  description: string | null;
  preset_type: ExpenseEntryPresetType;
  definition: ExpenseEntryPresetDefinitionItem[];
  is_active: boolean;
  usage_count: number;
  last_used_at: string | null;
  created_by: string | null;
}

export type ExpenseClaimStatus =
  "draft" | "in_review" | "returned" | "approved" | "cancelled";

/** UC-X004/X011: 経費明細が参照する勤怠実績・予定・出張申請の種別。金額計算・確定判定には
 *  使わず、入力補助・承認時の突合せ表示にのみ使う(docs/30-usecases-expense.md)。 */
export type ExpenseFactReferenceType =
  "attendance_day" | "schedule" | "business_trip";

/** 誰が支払ったか。法人カード等(employee以外)はreimbursement_amountが0円になる
 *  (「経費精算機能 設計・実装指示書」6.4)。 */
export type ExpensePaymentBearer =
  "employee" | "company" | "corporate_card" | "customer" | "other";

export interface ExpenseItem {
  id: string;
  claim_id?: string;
  category_id: number;
  category?: Pick<
    ExpenseCategory,
    "id" | "code" | "name" | "evidence_type_default"
  >;
  usage_date: string;
  /** 内容(自由記述)。交通費の場合は「出発地 → 到着地(手段)」形式の1行テキストをUI側で整形して設定する。 */
  description: string | null;
  amount: number;
  project_id: string | null;
  evidence_type: ExpenseEvidenceType;
  fact_reference_type: ExpenseFactReferenceType | null;
  fact_reference_id: string | null;
  /** UC-X009: 定期区間重複の自己申告による控除額。会社負担額はamount - commuting_deduction_amount。 */
  commuting_deduction_amount: number | null;
  payment_bearer?: ExpensePaymentBearer;
  /** 会社から社員へ返金する金額。payment_bearerがemployee以外なら0円(派生値・サーバー算出)。 */
  reimbursement_amount?: number;
  /** 区分固有の構造化データ。category.field_definitionsで定義したキーのみ許可される。 */
  attributes?: Record<string, unknown> | null;
  attachments?: Attachment[];
}

export interface ExpenseClaim {
  id: string;
  employee_id: string;
  employee?: User;
  /** 任意項目。「7月分の立替経費」等の申請タイトル。未設定時はUI側で対象期間から表示名を組み立てる。 */
  title?: string | null;
  /** 保存済み明細のusage_dateの最小値・最大値から自動算出される派生値。明細が無い作成直後はnull(原則2)。 */
  period_from: string | null;
  period_to: string | null;
  status: ExpenseClaimStatus;
  approver_user_id: string | null;
  approver?: User;
  total_amount: number;
  submitted_at: string | null;
  approved_at: string | null;
  items: ExpenseItem[];
}

export interface ExpenseClaimHistoryEntry {
  id: number;
  action: "drafted" | "submitted" | "approved" | "returned" | "cancelled";
  actor_user_id: string | null;
  comment: string | null;
  occurred_at: string;
}

/** 勤怠未提出督促(WarnUnsubmittedAttendanceHandler)の対象から個別に除外された
 *  社員×年月の組み合わせ。usage_start_date/hire_dateによる除外条件とは別の、
 *  管理者による汎用的な例外的対応の記録(docs/17-events.md
 *  attendance.submission_reminder_excluded)。 */
export interface AttendanceSubmissionReminderExclusion {
  id: string;
  user_id: string;
  user?: Pick<User, "id" | "name">;
  year_month: string;
  reason: string;
  excluded_by_user_id: string;
  created_at: string | null;
}

export interface Paginated<T> {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    total: number;
    per_page?: number;
  };
  links: {
    next: string | null;
    prev: string | null;
  };
}

/** UC-C012: 祝日iCalendarソースの直近1回分の同期結果。同期実行前・未同期ならnull。 */
export interface HolidayCalendarSyncSummary {
  added: number;
  updated: number;
  removed: number;
  /** 実際にこのカレンダーの祝日として反映された件数(手動変更保護でスキップされた分を除く)。 */
  applied: number;
  /** 手動変更保護のため反映がスキップされた件数。 */
  protected_conflicts: number;
}

/** UC-C012: 祝日iCalendarソース(祝日カレンダーの自動同期元)。 */
export interface HolidayCalendarSource {
  id: string;
  name: string;
  source_kind: "url" | "upload";
  ics_url: string | null;
  uploaded_ics_filename: string | null;
  sync_status: string;
  last_synced_at: string | null;
  last_error: string | null;
  disabled_at: string | null;
  last_sync_summary: HolidayCalendarSyncSummary | null;
}

export type CalendarBulkOperationType =
  | "calendar_apply"
  | "rotation_generate"
  | "bulk_edit";

export type CalendarBulkOperationConflictPolicy =
  | "skip_existing"
  | "overwrite"
  | "fail_on_conflict";

export type CalendarBulkOperationStatus =
  | "applied"
  | "reverted"
  | "failed";

/**
 * UC-C013: 一括操作の対象1件分の適用結果。プレビュー(`conflict`/`guard_blocked`/
 * `attributes`)と確定後の一覧・詳細(`id`/`employee_calendar_entry_id`/`error_code`)では
 * レスポンスの形が異なるため、両方をオプショナルとして受ける。
 */
export interface CalendarBulkOperationTarget {
  id?: string;
  user_id: string;
  work_date: string;
  conflict?: boolean;
  guard_blocked?: boolean;
  attributes?: Record<string, unknown>;
  employee_calendar_entry_id?: string | null;
  error_code?: string | null;
  result: "applied" | "skipped_existing" | "failed";
}

/** UC-C013: 複数従業員予定の一括操作(プレビュー→確定適用→取消)の1件。 */
export interface CalendarBulkOperation {
  id: string;
  operation_type: CalendarBulkOperationType;
  target_scope: Record<string, unknown>;
  conflict_policy: CalendarBulkOperationConflictPolicy;
  status: CalendarBulkOperationStatus;
  requested_by_user_id: string;
  applied_at: string | null;
  reverted_at: string | null;
  reason: string;
  targets?: CalendarBulkOperationTarget[];
}

/** UC-C013: プレビューAPIのレスポンス(保存しない)。 */
export interface CalendarBulkOperationPreview {
  targets: CalendarBulkOperationTarget[];
  conflict_count: number;
  executable: boolean;
}

export interface AdminCommandParameter {
  name: string;
  kind: "argument" | "option";
  required: boolean;
  array: boolean;
  accepts_value?: boolean;
  value_required?: boolean;
  default: unknown;
  description: string;
  ui: { control?: "year-month" | "checkbox" | "text" };
}

export interface AdminCommandDefinition {
  name: string;
  label: string;
  description: string;
  parameters: AdminCommandParameter[];
  without_overlapping: boolean;
}

export interface AdminCommandRun {
  id: string;
  command_name: string;
  parameters: Record<string, unknown>;
  status: "queued" | "running" | "succeeded" | "failed";
  requested_by_user_id: string;
  requested_by_user?: Pick<User, "id" | "name">;
  started_at: string | null;
  finished_at: string | null;
  exit_code: number | null;
  output: string | null;
  error_message: string | null;
  created_at: string;
}
