import { apiFetch } from './client'
import type { AttendanceSubmissionReminderExclusion } from './types'

/** 勤怠未提出督促の個別除外一覧を取得する(role:admin限定)。userIdを指定すると対象社員で絞り込む。 */
export function fetchAttendanceSubmissionReminderExclusions(
  userId?: string,
): Promise<AttendanceSubmissionReminderExclusion[]> {
  return apiFetch('/attendance-submission-reminder-exclusions', { query: { user_id: userId } })
}

export interface ExcludeAttendanceSubmissionReminderInput {
  user_id: string
  year_month: string
  reason: string
}

/** 特定の社員×年月を勤怠未提出督促の対象から個別に除外する(role:admin限定)。 */
export function excludeAttendanceSubmissionReminder(
  input: ExcludeAttendanceSubmissionReminderInput,
): Promise<AttendanceSubmissionReminderExclusion> {
  return apiFetch('/attendance-submission-reminder-exclusions', { method: 'POST', body: input })
}
