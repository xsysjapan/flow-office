import { apiFetch } from './client'
import type { CompensatoryLeaveGrant, CompensatoryLeaveRequest, PaidLeaveType } from './types'

/** UC相当: 自分の代休残数(付与)を取得する。付与は休日出勤の勤怠実績から自動導出されるため、付与のCRUDは無い。 */
export function fetchMyCompensatoryLeaveGrants(): Promise<CompensatoryLeaveGrant[]> {
  return apiFetch('/compensatory-leave/grants/mine')
}

export function fetchMyCompensatoryLeaveRequests(): Promise<CompensatoryLeaveRequest[]> {
  return apiFetch('/compensatory-leave/requests/mine')
}

export interface CreateCompensatoryLeaveRequestInput {
  target_date: string
  leave_type: PaidLeaveType
  hours?: number
  /** system_settings.compensatory_leave_requires_approval が false の場合は省略可(承認不要)。 */
  approver_user_id?: string
  reason?: string
}

export function createCompensatoryLeaveRequest(input: CreateCompensatoryLeaveRequestInput): Promise<CompensatoryLeaveRequest> {
  return apiFetch('/compensatory-leave/requests', { method: 'POST', body: input })
}

export function cancelCompensatoryLeaveRequest(id: string): Promise<CompensatoryLeaveRequest> {
  return apiFetch(`/compensatory-leave/requests/${id}/cancel`, { method: 'POST' })
}
