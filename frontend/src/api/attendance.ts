import { apiFetch } from './client'
import type {
  AttendanceDailyCalculationAdjustment,
  AttendanceDay,
  AttendanceDayDefaults,
  AttendanceMonth,
  AttendanceMonthlyCalculationTotals,
  AttendancePunch,
  FlexSettlementSummary,
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
export function fetchWeek(startDate: string, userId?: number): Promise<AttendanceDay[]> {
  return apiFetch('/attendance/week', { query: { start_date: startDate, user_id: userId } })
}

/** 日次勤怠の入力画面(未入力の日)を開いた際の初期値(打刻→勤務予定→システム既定の優先順)。 */
export function fetchAttendanceDayDefaults(userId: number, workDate: string): Promise<AttendanceDayDefaults> {
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

export function updateAttendanceDay(id: number, input: EditAttendanceDayInput): Promise<AttendanceDay> {
  return apiFetch(`/attendance/days/${id}`, { method: 'PUT', body: input })
}

/** 日次登録後、区分ごとの時間(所定労働・残業・深夜・休日労働)を手動で補正する。
 *  実績(出勤・退勤・休憩)が再編集され再計算されるとこの補正は解除される。 */
export function adjustAttendanceDailyCalculation(
  id: number,
  input: AttendanceDailyCalculationAdjustment,
): Promise<AttendanceDay> {
  return apiFetch(`/attendance/days/${id}/calculation`, { method: 'PUT', body: input })
}

export interface CreateAttendanceDayInput {
  user_id: number
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

export type AttendanceDayPunchLogAction = 'leave_punches' | 'delete_punches' | 'recreate_from_punches'

export interface DeleteAttendanceDayInput {
  reason: string
  punch_log_action: AttendanceDayPunchLogAction
}

/** UC-A015: 日次勤怠を削除する。承認前(未提出・提出済み・差戻し)のみ可能。 */
export function deleteAttendanceDay(id: number, input: DeleteAttendanceDayInput): Promise<{ deleted: boolean }> {
  return apiFetch(`/attendance/days/${id}`, { method: 'DELETE', body: input })
}

/** UC-A012: 指定した勤務日範囲の打刻ログ(訂正済み・削除済みも含む)を取得する。 */
export function fetchPunches(params: { from?: string; to?: string } = {}): Promise<AttendancePunch[]> {
  return apiFetch('/attendance-punches', { query: params })
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
export function correctPunch(id: number, input: CorrectAttendancePunchInput): Promise<AttendancePunch> {
  return apiFetch(`/attendance-punches/${id}`, { method: 'PUT', body: input })
}

/** UC-A014: 打刻ログを削除する。戻り値は削除済み状態になった元の打刻ログ。 */
export function deletePunch(id: number, reason: string): Promise<AttendancePunch> {
  return apiFetch(`/attendance-punches/${id}`, { method: 'DELETE', body: { reason } })
}

/** UC-A007: 月次勤怠。userIdを指定すると自分以外の社員を参照できる(adminのみ)。 */
export function fetchMonth(yearMonth: string, userId?: number): Promise<{
  days: AttendanceDay[]
  month: AttendanceMonth | null
  flex_settlement_summary: FlexSettlementSummary | null
  monthly_calculation_totals: AttendanceMonthlyCalculationTotals
}> {
  return apiFetch(`/attendance/months/${yearMonth}`, { query: { user_id: userId } })
}

export function submitMonth(yearMonth: string, approverUserId: number): Promise<AttendanceMonth> {
  return apiFetch(`/attendance/months/${yearMonth}/submit`, {
    method: 'POST',
    body: { approver_user_id: approverUserId },
  })
}

export function fetchMyMonths(): Promise<AttendanceMonth[]> {
  return apiFetch('/attendance/months/mine')
}

/** 管理者が対象社員を選んで月次勤怠一覧(月次・週次・日次の勤怠参照)を確認する。 */
export function fetchMonthsForUser(userId: number): Promise<AttendanceMonth[]> {
  return apiFetch(`/attendance/months/user/${userId}`)
}

export function fetchMonthsToApprove(): Promise<AttendanceMonth[]> {
  return apiFetch('/attendance/months/to-approve')
}

export function approveMonth(id: number): Promise<AttendanceMonth> {
  return apiFetch(`/attendance-months/${id}/approve`, { method: 'POST' })
}

export function returnMonth(id: number, comment: string): Promise<AttendanceMonth> {
  return apiFetch(`/attendance-months/${id}/return`, { method: 'POST', body: { comment } })
}

export function closeMonth(id: number): Promise<AttendanceMonth> {
  return apiFetch(`/attendance-months/${id}/close`, { method: 'POST' })
}
