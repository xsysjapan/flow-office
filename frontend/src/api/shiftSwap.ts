import { apiFetch } from './client'
import type { ShiftSwapRequest } from './types'

export interface CreateShiftSwapRequestInput {
  /** 振替対象の休日(この日に出勤する)。 */
  target_date: string
  /** 振替先の休日(代わりに休む日)。 */
  substitute_date: string
  /** system_settings.shift_swap_requires_approval が false の場合は省略可(承認不要)。 */
  approver_user_id?: string
  reason?: string
}

/** 承認不要の設定(shift_swap_requires_approval=false)の直接の申請。承認が必要な設定では422になる。 */
export function createShiftSwapRequest(input: CreateShiftSwapRequestInput): Promise<ShiftSwapRequest> {
  return apiFetch('/shift-swap/requests', { method: 'POST', body: input })
}

/** 承認が必要な設定(shift_swap_requires_approval=true)の申請(承認者を指定してワークフローを作る)。承認不要の設定では422になる。 */
export function createShiftSwapWorkflowRequest(input: CreateShiftSwapRequestInput): Promise<ShiftSwapRequest> {
  return apiFetch('/shift-swap/requests/workflow', { method: 'POST', body: input })
}

export function approveShiftSwapRequest(id: string): Promise<ShiftSwapRequest> {
  return apiFetch(`/shift-swap/requests/${id}/approve`, { method: 'POST' })
}

export function returnShiftSwapRequest(id: string, comment: string): Promise<ShiftSwapRequest> {
  return apiFetch(`/shift-swap/requests/${id}/return`, { method: 'POST', body: { comment } })
}

export function cancelShiftSwapRequest(id: string): Promise<ShiftSwapRequest> {
  return apiFetch(`/shift-swap/requests/${id}/cancel`, { method: 'POST' })
}

export function fetchMyShiftSwapRequests(): Promise<ShiftSwapRequest[]> {
  return apiFetch('/shift-swap/requests/mine')
}

export function fetchShiftSwapRequestsToApprove(): Promise<ShiftSwapRequest[]> {
  return apiFetch('/shift-swap/requests/to-approve')
}
