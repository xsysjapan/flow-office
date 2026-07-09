import { apiFetch } from './client'
import type { PaidLeaveGrant, PaidLeaveGrantRule } from './types'

export function fetchMyPaidLeaveGrants(): Promise<PaidLeaveGrant[]> {
  return apiFetch('/paid-leave/grants/mine')
}

export function fetchPaidLeaveGrantsForUser(userId: number): Promise<PaidLeaveGrant[]> {
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
  user_id: number
  granted_on: string
  expires_on: string
  granted_days: number
  grant_reason?: string
}

export function grantPaidLeave(input: GrantPaidLeaveInput): Promise<PaidLeaveGrant> {
  return apiFetch('/paid-leave/grants', { method: 'POST', body: input })
}
