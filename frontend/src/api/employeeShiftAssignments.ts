import { apiFetch } from './client'
import type { EmployeeShiftAssignment, ShiftScheduleReview } from './types'

export function fetchShiftAssignments(userId: string, from: string, to: string): Promise<EmployeeShiftAssignment[]> {
  return apiFetch<{ data: EmployeeShiftAssignment[]; provisional: boolean }>('/employee-calendar-entries', {
    query: { user_id: userId, from, to },
  }).then((response) => response.data)
}

export interface GenerateShiftAssignmentsInput {
  user_id: string
  work_style_id: string
  from: string
  to: string
}

export function generateShiftAssignments(input: GenerateShiftAssignmentsInput): Promise<EmployeeShiftAssignment[]> {
  return apiFetch('/employee-calendar-entries/generate', { method: 'POST', body: input })
}

/** UC-C004 手順3〜4: 3交代制シフト表で、社員の特定日にシフトパターンを割り当てる。 */
export interface AssignShiftPatternDayInput {
  user_id: string
  work_style_id: string
  work_date: string
  shift_pattern_id: string
  is_legal_holiday?: boolean
  is_company_holiday?: boolean
}

export function assignShiftPatternDay(input: AssignShiftPatternDayInput): Promise<EmployeeShiftAssignment> {
  return apiFetch('/employee-calendar-entries/assign-pattern', { method: 'POST', body: input })
}

export interface ShiftScheduleTarget {
  department?: string
  user_ids?: string[]
  year_month: string
}

/** UC-C004 手順5: 公開前に法定休日不足・連続勤務・月間予定時間を確認する(読み取り専用、警告のみ)。 */
export function reviewShiftSchedule(target: ShiftScheduleTarget): Promise<ShiftScheduleReview> {
  return apiFetch('/employee-calendar-entries/review', {
    query: { department: target.department, user_ids: target.user_ids, year_month: target.year_month },
  })
}

/** UC-C004 手順6: 3交代制シフト表を公開する。 */
export function publishShiftSchedule(target: ShiftScheduleTarget): Promise<{ published_count: number }> {
  return apiFetch('/employee-calendar-entries/publish', { method: 'POST', body: target })
}

/** 週次・月次一括入力: 曜日ごとの開始/終了時刻・休憩分。ISO曜日(1=月〜7=日)をキーとする。 */
export type WeekdayShiftEntry = { start_time: string; end_time: string; break_minutes: number } | null
export type WeeklyShiftPattern = Record<number, WeekdayShiftEntry>
/** 日付('YYYY-MM-DD')をキーとする、週次パターンへの日単位の上書き。 */
export type DayShiftOverrides = Record<string, WeekdayShiftEntry>

export interface PatternShiftAssignmentsInput {
  user_id: string
  work_style_id: string
  from: string
  to: string
  weekly_pattern: WeeklyShiftPattern
  day_overrides?: DayShiftOverrides
  overwrite_mode?: 'skip_edited' | 'overwrite_all'
}

export type PreviewPatternShiftAssignmentsInput = Omit<PatternShiftAssignmentsInput, 'user_id' | 'work_style_id'>

export interface PatternShiftPreviewDay {
  date: string
  weekday: number
  is_working_day: boolean
  start_time: string | null
  end_time: string | null
  break_minutes: number
  source: 'day_override' | 'weekly_pattern' | 'none'
}

/** 確定前に、週次・月次パターンの展開結果を確認する(永続化しない)。 */
export function previewPatternShiftAssignments(
  input: PreviewPatternShiftAssignmentsInput,
): Promise<{ days: PatternShiftPreviewDay[] }> {
  return apiFetch('/employee-calendar-entries/preview-pattern', { method: 'POST', body: input })
}

export interface GeneratePatternShiftAssignmentsResult {
  generated: EmployeeShiftAssignment[]
  generated_count: number
  skipped_dates: string[]
}

/** 週次・月次パターンを確定し、指定期間の勤務予定を一括生成する。 */
export function generatePatternShiftAssignments(
  input: PatternShiftAssignmentsInput,
): Promise<GeneratePatternShiftAssignmentsResult> {
  return apiFetch('/employee-calendar-entries/generate-pattern', { method: 'POST', body: input })
}
