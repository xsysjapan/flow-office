import { apiFetch } from './client'
import type { EmployeeShiftAssignment, ShiftScheduleReview } from './types'

export function fetchShiftAssignments(userId: string, from: string, to: string): Promise<EmployeeShiftAssignment[]> {
  return apiFetch('/employee-shift-assignments', { query: { user_id: userId, from, to } })
}

export interface GenerateShiftAssignmentsInput {
  user_id: string
  work_style_id: number
  from: string
  to: string
}

export function generateShiftAssignments(input: GenerateShiftAssignmentsInput): Promise<EmployeeShiftAssignment[]> {
  return apiFetch('/employee-shift-assignments/generate', { method: 'POST', body: input })
}

/** UC-C004 手順3〜4: 3交代制シフト表で、社員の特定日にシフトパターンを割り当てる。 */
export interface AssignShiftPatternDayInput {
  user_id: string
  work_style_id: number
  work_date: string
  shift_pattern_id: number
  is_legal_holiday?: boolean
  is_company_holiday?: boolean
}

export function assignShiftPatternDay(input: AssignShiftPatternDayInput): Promise<EmployeeShiftAssignment> {
  return apiFetch('/employee-shift-assignments/assign-pattern', { method: 'POST', body: input })
}

export interface ShiftScheduleTarget {
  department?: string
  user_ids?: string[]
  year_month: string
}

/** UC-C004 手順5: 公開前に法定休日不足・連続勤務・月間予定時間を確認する(読み取り専用、警告のみ)。 */
export function reviewShiftSchedule(target: ShiftScheduleTarget): Promise<ShiftScheduleReview> {
  return apiFetch('/employee-shift-assignments/review', {
    query: { department: target.department, user_ids: target.user_ids, year_month: target.year_month },
  })
}

/** UC-C004 手順6: 3交代制シフト表を公開する。 */
export function publishShiftSchedule(target: ShiftScheduleTarget): Promise<{ published_count: number }> {
  return apiFetch('/employee-shift-assignments/publish', { method: 'POST', body: target })
}
