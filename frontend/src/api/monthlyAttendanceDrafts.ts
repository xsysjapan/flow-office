import { apiFetch } from './client'
import type { FieldProvenance, MonthlyAttendanceDraft } from './types'

export function fetchMyMonthlyAttendanceDrafts(): Promise<MonthlyAttendanceDraft[]> {
  return apiFetch('/attendance/monthly-drafts/mine')
}

export function fetchMonthlyAttendanceDraft(id: number): Promise<MonthlyAttendanceDraft> {
  return apiFetch(`/attendance/monthly-drafts/${id}`)
}

export function fetchMonthlyAttendanceDraftFields(id: number): Promise<FieldProvenance[]> {
  return apiFetch(`/attendance/monthly-drafts/${id}/fields`)
}

export interface ValidateMonthlyAttendanceDraftResult {
  draft: MonthlyAttendanceDraft
  unconfirmed_fields: string[]
}

export function validateMonthlyAttendanceDraft(id: number): Promise<ValidateMonthlyAttendanceDraftResult> {
  return apiFetch(`/attendance/monthly-drafts/${id}/validate`, { method: 'POST' })
}

export function confirmMonthlyAttendanceDraftField(
  draftId: number,
  fieldProvenanceId: number,
): Promise<{ field_name: string; confirmed_at: string | null }> {
  return apiFetch(`/attendance/monthly-drafts/${draftId}/fields/${fieldProvenanceId}/confirm`, { method: 'POST' })
}

export function submitMonthlyAttendanceDraft(id: number, approverUserId: number): Promise<MonthlyAttendanceDraft> {
  return apiFetch(`/attendance/monthly-drafts/${id}/submit`, {
    method: 'POST',
    body: { approver_user_id: approverUserId },
  })
}
