import { apiFetch } from './client'
import type {
  AttendanceDailyCalculationAdjustment,
  AttendanceDay,
  AttendanceDayDefaults,
  AttendanceMonth,
  AttendanceMonthlyCalculationTotals,
  AttendanceMonthStatus,
  AttendancePunch,
  FlexSettlementSummary,
  Paginated,
  SpecialLeaveBreakdownEntry,
  WorkLocationType,
} from './types'

/** 遅刻・早退等を欠勤時間として扱う区間の入力(有給休暇・特別休暇は含まない)。 */
export interface LeaveSegmentInput {
  start: string
  end: string
  note?: string | null
}

export function fetchToday(): Promise<AttendanceDay> {
  return apiFetch('/attendance/today')
}

/** UC-A006: 週次勤怠(startDateを含む週の月曜〜日曜)。userIdを指定すると自分以外の社員を
 *  参照できる(adminのみ)。 */
export function fetchWeek(startDate: string, userId?: string): Promise<AttendanceDay[]> {
  return apiFetch('/attendance/week', { query: { start_date: startDate, user_id: userId } })
}

/** 日次勤怠の入力画面(未入力の日)を開いた際の初期値(打刻→勤務予定→システム既定の優先順)。 */
export function fetchAttendanceDayDefaults(userId: string, workDate: string): Promise<AttendanceDayDefaults> {
  return apiFetch('/attendance/day-defaults', { query: { user_id: userId, work_date: workDate } })
}

export function clockIn(): Promise<AttendanceDay> {
  return apiFetch('/attendance/clock-in', { method: 'POST' })
}

export function startBreak(): Promise<AttendanceDay> {
  return apiFetch('/attendance/break/start', { method: 'POST' })
}

export function endBreak(): Promise<AttendanceDay> {
  return apiFetch('/attendance/break/end', { method: 'POST' })
}

export function clockOut(): Promise<AttendanceDay> {
  return apiFetch('/attendance/clock-out', { method: 'POST' })
}

export interface EditAttendanceDayInput {
  actual_start_at?: string | null
  actual_end_at?: string | null
  breaks?: Array<{ start: string; end?: string | null }>
  work_type?: string | null
  /** 未指定(キー自体を省略)の場合、サーバー側は既存の値を維持する。明示的にnullを
   *  送ると値をクリアする。 */
  work_location_type?: WorkLocationType | null
  note?: string | null
  leave_segments?: LeaveSegmentInput[]
  reason: string
}

export function updateAttendanceDay(id: string, input: EditAttendanceDayInput): Promise<AttendanceDay> {
  return apiFetch(`/attendance/days/${id}`, { method: 'PUT', body: input })
}

/** 日次登録後、区分ごとの時間(所定労働・残業・深夜・休日労働)を手動で補正する。
 *  実績(出勤・退勤・休憩)が再編集され再計算されるとこの補正は解除される。 */
export function adjustAttendanceDailyCalculation(
  id: string,
  input: AttendanceDailyCalculationAdjustment,
): Promise<AttendanceDay> {
  return apiFetch(`/attendance/days/${id}/calculation`, { method: 'PUT', body: input })
}

export interface CreateAttendanceDayInput {
  user_id: string
  work_date: string
  actual_start_at?: string | null
  actual_end_at?: string | null
  breaks?: Array<{ start: string; end?: string | null }>
  work_type?: string | null
  work_location_type?: WorkLocationType | null
  note?: string | null
  leave_segments?: LeaveSegmentInput[]
  reason: string
}

/** UC-A016: 出勤日を新規作成する。打刻の有無にかかわらず、月が締められるまではいつでも作成できる。 */
export function createAttendanceDay(input: CreateAttendanceDayInput): Promise<AttendanceDay> {
  return apiFetch('/attendance/days', { method: 'POST', body: input })
}

/** 週次・月次一括入力: 曜日ごとの実際の出退勤・休憩時刻。ISO曜日(1=月〜7=日)をキーとする。 */
export type AttendanceWeekdayEntry = {
  start_time: string
  end_time: string
  break_start_time?: string
  break_end_time?: string
} | null
export type WeeklyAttendancePattern = Record<number, AttendanceWeekdayEntry>
/** 日付('YYYY-MM-DD')をキーとする、週次パターンへの日単位の上書き。 */
export type AttendanceDayOverrides = Record<string, AttendanceWeekdayEntry>

export interface PreviewAttendancePatternInput {
  from: string
  to: string
  utc_offset: string
  weekly_pattern: WeeklyAttendancePattern
  day_overrides?: AttendanceDayOverrides
}

export interface AttendancePatternPreviewDay {
  date: string
  weekday: number
  start_time: string
  end_time: string
  break_start_time: string | null
  break_end_time: string | null
  has_existing_day: boolean
  is_locked: boolean
}

/** 確定前に、週次・月次パターンの実績展開結果を確認する(永続化しない)。 */
export function previewAttendancePattern(
  input: PreviewAttendancePatternInput,
): Promise<{ days: AttendancePatternPreviewDay[] }> {
  return apiFetch('/attendance/days/preview-pattern', { method: 'POST', body: input })
}

export interface GenerateAttendancePatternInput extends PreviewAttendancePatternInput {
  user_id: string
  overwrite_mode?: 'skip_existing' | 'overwrite_existing'
  reason: string
}

export type AttendancePatternResultStatus = 'created' | 'updated' | 'skipped_existing' | 'rejected'

export interface AttendancePatternResultDay {
  date: string
  status: AttendancePatternResultStatus
  message: string | null
}

export interface GenerateAttendancePatternResult {
  results: AttendancePatternResultDay[]
  created_count: number
  updated_count: number
  skipped_count: number
  rejected_count: number
}

/** 週次・月次パターンを確定し、指定期間の実績(attendance_days)を一括作成・更新する。 */
export function generateAttendancePattern(
  input: GenerateAttendancePatternInput,
): Promise<GenerateAttendancePatternResult> {
  return apiFetch('/attendance/days/generate-pattern', { method: 'POST', body: input })
}

export type AttendanceDayPunchLogAction = 'leave_punches' | 'delete_punches' | 'recreate_from_punches'

export interface DeleteAttendanceDayInput {
  reason: string
  punch_log_action: AttendanceDayPunchLogAction
}

/** UC-A015: 日次勤怠を削除する。承認前(未提出・提出済み・差戻し)のみ可能。 */
export function deleteAttendanceDay(id: string, input: DeleteAttendanceDayInput): Promise<{ deleted: boolean }> {
  return apiFetch(`/attendance/days/${id}`, { method: 'DELETE', body: input })
}

/** UC-A012: 指定した勤務日範囲の打刻ログ(訂正済み・削除済みも含む)を取得する。
 *  userIdを指定すると自分以外の社員の打刻ログを参照できる(admin、またはfrom/toの期間の
 *  年月の承認者)。 */
export function fetchPunches(params: { from?: string; to?: string; userId?: string } = {}): Promise<AttendancePunch[]> {
  const { userId, ...rest } = params
  return apiFetch('/attendance-punches', { query: { ...rest, user_id: userId } })
}

export interface CreateAttendancePunchInput {
  work_date: string
  punch_type: AttendancePunch['punch_type']
  punched_at: string
  source: string
}

/** UC-A012: 打刻ログを記録する。 */
export function createPunch(input: CreateAttendancePunchInput): Promise<AttendancePunch> {
  return apiFetch('/attendance-punches', { method: 'POST', body: input })
}

export interface CorrectAttendancePunchInput {
  punch_type: AttendancePunch['punch_type']
  punched_at: string
  reason: string
}

/** UC-A013: 打刻ログを訂正する。戻り値は訂正後に追記された新しい打刻ログ。 */
export function correctPunch(id: string, input: CorrectAttendancePunchInput): Promise<AttendancePunch> {
  return apiFetch(`/attendance-punches/${id}`, { method: 'PUT', body: input })
}

/** UC-A014: 打刻ログを削除する。戻り値は削除済み状態になった元の打刻ログ。 */
export function deletePunch(id: string, reason: string): Promise<AttendancePunch> {
  return apiFetch(`/attendance-punches/${id}`, { method: 'DELETE', body: { reason } })
}

/** UC-A007: 月次勤怠。userIdを指定すると自分以外の社員を参照できる(adminのみ)。 */
export function fetchMonth(yearMonth: string, userId?: string): Promise<{
  days: AttendanceDay[]
  month: AttendanceMonth | null
  flex_settlement_summary: FlexSettlementSummary | null
  monthly_calculation_totals: AttendanceMonthlyCalculationTotals
  /** 特別休暇の種類ごとの内訳。バックエンドは常に返すが、既存のテストモック等との
   *  互換性のためoptionalにしておく(未指定時はAttendanceCalculationSummaryが従来通り
   *  合計のみを表示する)。 */
  special_leave_breakdown?: SpecialLeaveBreakdownEntry[]
}> {
  return apiFetch(`/attendance/months/${yearMonth}`, { query: { user_id: userId } })
}

/** system_settings.attendance_requires_approval が false の場合は approverUserId を省略できる(承認不要)。 */
export function submitMonth(yearMonth: string, approverUserId?: string): Promise<AttendanceMonth> {
  return apiFetch(`/attendance/months/${yearMonth}/submit`, {
    method: 'POST',
    body: approverUserId ? { approver_user_id: approverUserId } : {},
  })
}

export function fetchMyMonths(): Promise<AttendanceMonth[]> {
  return apiFetch('/attendance/months/mine')
}

/** 管理者が対象社員を選んで月次勤怠一覧(月次・週次・日次の勤怠参照)を確認する。 */
export function fetchMonthsForUser(userId: string): Promise<AttendanceMonth[]> {
  return apiFetch(`/attendance/months/user/${userId}`)
}

export interface FetchMonthsToApproveOptions {
  status?: AttendanceMonthStatus
  yearMonth?: string
  userId?: string
  page?: number
  perPage?: number
}

/** UC-A009/UC-A010: 承認待ちの月次勤怠一覧。ステータス・年月・対象社員での絞り込みと
 *  ページングに対応する。 */
export function fetchMonthsToApprove(options: FetchMonthsToApproveOptions = {}): Promise<Paginated<AttendanceMonth>> {
  const { status, yearMonth, userId, page, perPage } = options
  return apiFetch('/attendance/months/to-approve', {
    query: { status, year_month: yearMonth, user_id: userId, page, per_page: perPage },
  })
}

export function approveMonth(id: string): Promise<AttendanceMonth> {
  return apiFetch(`/attendance-months/${id}/approve`, { method: 'POST' })
}

export function returnMonth(id: string, comment: string): Promise<AttendanceMonth> {
  return apiFetch(`/attendance-months/${id}/return`, { method: 'POST', body: { comment } })
}

export function closeMonth(id: string): Promise<AttendanceMonth> {
  return apiFetch(`/attendance-months/${id}/close`, { method: 'POST' })
}

/** idで単一の月次勤怠を取得する。バックオフィスタスク詳細から締め状態を確認する用途。 */
export function fetchAttendanceMonthById(id: string): Promise<AttendanceMonth> {
  return apiFetch(`/attendance-months/${id}`)
}
