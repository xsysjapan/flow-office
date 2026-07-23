import { apiFetch } from './client'
import type { PaidLeaveGrant, PaidLeaveGrantRule, PaidLeaveRequest, PaidLeaveType, StoredEvent } from './types'

export function fetchMyPaidLeaveGrants(): Promise<PaidLeaveGrant[]> {
  return apiFetch('/paid-leave/grants/mine')
}

export function fetchPaidLeaveGrantsForUser(userId: string): Promise<PaidLeaveGrant[]> {
  return apiFetch(`/paid-leave/grants/user/${userId}`)
}

export function fetchPaidLeaveGrantRules(): Promise<PaidLeaveGrantRule[]> {
  return apiFetch('/paid-leave/grant-rules')
}

export interface CreatePaidLeaveGrantRuleInput {
  name: string
  work_style_id?: number
  min_attendance_rate?: number
  first_grant_after_months?: number
  grant_cycle_months?: number
  is_active?: boolean
  steps?: Array<{ continuous_service_months: number; grant_days: number }>
}

export function createPaidLeaveGrantRule(input: CreatePaidLeaveGrantRuleInput): Promise<PaidLeaveGrantRule> {
  return apiFetch('/paid-leave/grant-rules', { method: 'POST', body: input })
}

export interface GrantPaidLeaveInput {
  user_id: string
  granted_on: string
  expires_on: string
  granted_days: number
  grant_reason?: string
}

export function grantPaidLeave(input: GrantPaidLeaveInput): Promise<PaidLeaveGrant> {
  return apiFetch('/paid-leave/grants', { method: 'POST', body: input })
}

export function fetchMyPaidLeaveRequests(): Promise<PaidLeaveRequest[]> {
  return apiFetch('/paid-leave/requests/mine')
}

export function fetchPaidLeaveRequestsToApprove(): Promise<PaidLeaveRequest[]> {
  return apiFetch('/paid-leave/requests/to-approve')
}

export interface CreatePaidLeaveRequestInput {
  target_date: string
  leave_type: PaidLeaveType
  hours?: number
  approver_user_id: string
  reason?: string
}

export function createPaidLeaveRequest(input: CreatePaidLeaveRequestInput): Promise<PaidLeaveRequest> {
  return apiFetch('/paid-leave/requests', { method: 'POST', body: input })
}

export function approvePaidLeaveRequest(id: string): Promise<PaidLeaveRequest> {
  return apiFetch(`/paid-leave/requests/${id}/approve`, { method: 'POST' })
}

export function returnPaidLeaveRequest(id: string, comment: string): Promise<PaidLeaveRequest> {
  return apiFetch(`/paid-leave/requests/${id}/return`, { method: 'POST', body: { comment } })
}

export function cancelPaidLeaveRequest(id: string): Promise<PaidLeaveRequest> {
  return apiFetch(`/paid-leave/requests/${id}/cancel`, { method: 'POST' })
}

/** UC-P007: 自分の有給履歴(付与・申請・承認・差戻し・取消・消化)を新しい順に取得する。 */
export function fetchMyPaidLeaveHistory(): Promise<StoredEvent[]> {
  return apiFetch('/paid-leave/history/mine')
}

/** UC-P007: 管理者・人事担当者が対象社員の有給履歴を取得する。 */
export function fetchPaidLeaveHistoryForUser(userId: string): Promise<StoredEvent[]> {
  return apiFetch(`/paid-leave/history/user/${userId}`)
}
