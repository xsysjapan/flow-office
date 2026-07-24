import { apiFetch } from './client'
import type { PaidLeaveType, SpecialLeaveGrant, SpecialLeaveGrantRule, SpecialLeaveRequest, SpecialLeaveType, StoredEvent } from './types'

export function fetchSpecialLeaveTypes(): Promise<SpecialLeaveType[]> {
  return apiFetch('/special-leave/types')
}

export interface CreateSpecialLeaveTypeInput {
  name: string
  is_active?: boolean
}

export function createSpecialLeaveType(input: CreateSpecialLeaveTypeInput): Promise<SpecialLeaveType> {
  return apiFetch('/special-leave/types', { method: 'POST', body: input })
}

export interface UpdateSpecialLeaveTypeInput {
  name: string
  is_active?: boolean
}

export function updateSpecialLeaveType(id: number, input: UpdateSpecialLeaveTypeInput): Promise<SpecialLeaveType> {
  return apiFetch(`/special-leave/types/${id}`, { method: 'PUT', body: input })
}

export function fetchMySpecialLeaveGrants(): Promise<SpecialLeaveGrant[]> {
  return apiFetch('/special-leave/grants/mine')
}

export function fetchSpecialLeaveGrantsForUser(userId: string): Promise<SpecialLeaveGrant[]> {
  return apiFetch(`/special-leave/grants/user/${userId}`)
}

export function fetchSpecialLeaveGrantRules(): Promise<SpecialLeaveGrantRule[]> {
  return apiFetch('/special-leave/grant-rules')
}

export interface CreateSpecialLeaveGrantRuleInput {
  special_leave_type_id: number
  name: string
  work_style_id?: string
  min_attendance_rate?: number
  first_grant_after_months?: number
  grant_cycle_months?: number
  expires_after_months?: number
  is_active?: boolean
  steps?: Array<{ continuous_service_months: number; grant_days: number }>
}

export function createSpecialLeaveGrantRule(input: CreateSpecialLeaveGrantRuleInput): Promise<SpecialLeaveGrantRule> {
  return apiFetch('/special-leave/grant-rules', { method: 'POST', body: input })
}

export interface GrantSpecialLeaveInput {
  user_id: string
  special_leave_type_id: number
  granted_on: string
  /** 未指定の場合は失効しない付与になる。 */
  expires_on?: string
  granted_days: number
  grant_reason?: string
}

export function grantSpecialLeave(input: GrantSpecialLeaveInput): Promise<SpecialLeaveGrant> {
  return apiFetch('/special-leave/grants', { method: 'POST', body: input })
}

export function fetchMySpecialLeaveRequests(): Promise<SpecialLeaveRequest[]> {
  return apiFetch('/special-leave/requests/mine')
}

export function fetchSpecialLeaveRequestsToApprove(): Promise<SpecialLeaveRequest[]> {
  return apiFetch('/special-leave/requests/to-approve')
}

export interface CreateSpecialLeaveRequestInput {
  special_leave_type_id: number
  target_date: string
  leave_type: PaidLeaveType
  hours?: number
  approver_user_id: string
  reason?: string
}

export function createSpecialLeaveRequest(input: CreateSpecialLeaveRequestInput): Promise<SpecialLeaveRequest> {
  return apiFetch('/special-leave/requests', { method: 'POST', body: input })
}

export function approveSpecialLeaveRequest(id: string): Promise<SpecialLeaveRequest> {
  return apiFetch(`/special-leave/requests/${id}/approve`, { method: 'POST' })
}

export function returnSpecialLeaveRequest(id: string, comment: string): Promise<SpecialLeaveRequest> {
  return apiFetch(`/special-leave/requests/${id}/return`, { method: 'POST', body: { comment } })
}

export function cancelSpecialLeaveRequest(id: string): Promise<SpecialLeaveRequest> {
  return apiFetch(`/special-leave/requests/${id}/cancel`, { method: 'POST' })
}

/** 自分の特別休暇履歴(付与・申請・承認・差戻し・取消・消化)を新しい順に取得する。 */
export function fetchMySpecialLeaveHistory(): Promise<StoredEvent[]> {
  return apiFetch('/special-leave/history/mine')
}

/** 管理者・人事担当者が対象社員の特別休暇履歴を取得する。 */
export function fetchSpecialLeaveHistoryForUser(userId: string): Promise<StoredEvent[]> {
  return apiFetch(`/special-leave/history/user/${userId}`)
}
