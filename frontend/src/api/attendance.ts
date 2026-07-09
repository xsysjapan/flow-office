import { apiFetch } from './client'
import type { AttendanceDay, AttendanceMonth } from './types'

export function fetchToday(): Promise<AttendanceDay> {
  return apiFetch('/attendance/today')
}

/** UC-A006: 週次勤怠(startDateを含む週の月曜〜日曜)。 */
export function fetchWeek(startDate: string): Promise<AttendanceDay[]> {
  return apiFetch('/attendance/week', { query: { start_date: startDate } })
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
  note?: string | null
  reason: string
}

export function updateAttendanceDay(id: number, input: EditAttendanceDayInput): Promise<AttendanceDay> {
  return apiFetch(`/attendance/days/${id}`, { method: 'PUT', body: input })
}

export function fetchMonth(yearMonth: string): Promise<{ days: AttendanceDay[]; month: AttendanceMonth | null }> {
  return apiFetch(`/attendance/months/${yearMonth}`)
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
