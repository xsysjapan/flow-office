import { apiFetch } from './client'
import type { PaidLeaveGrant, PaidLeaveGrantRule, PaidLeaveRequest, PaidLeaveType, PaidLeaveUsage, StoredEvent } from './types'

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
  work_style_id?: string
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

/** 使用日数が0の付与のみ取消可能(422で拒否される場合がある)。 */
export function revokePaidLeaveGrant(grantId: string, reason?: string): Promise<PaidLeaveGrant> {
  return apiFetch(`/paid-leave/grants/${grantId}/revoke`, { method: 'POST', body: { reason } })
}

export function fetchMyPaidLeaveRequests(): Promise<PaidLeaveRequest[]> {
  return apiFetch('/paid-leave/requests/mine')
}

export interface CreatePaidLeaveRequestInput {
  target_date: string
  leave_type: PaidLeaveType
  hours?: number
  /** system_settings.paid_leave_requires_approval が false の場合は省略可(承認不要)。 */
  approver_user_id?: string
  reason?: string
  /** 複数日をまとめて申請する場合、同一の申請操作内で生成した同じ値を全日分に渡す
   *  (承認者は対応するworkflow_requestのいずれか1件を承認するだけで、この値を共有する
   *  他の申請もまとめて承認できるようになる)。単日申請では省略する。 */
  request_group_id?: string
}

export function createPaidLeaveRequest(input: CreatePaidLeaveRequestInput): Promise<PaidLeaveRequest> {
  return apiFetch('/paid-leave/requests', { method: 'POST', body: input })
}

export function cancelPaidLeaveRequest(id: string): Promise<PaidLeaveRequest> {
  return apiFetch(`/paid-leave/requests/${id}/cancel`, { method: 'POST' })
}

/** 管理者・人事担当者が対象社員の有給消化記録(usage)一覧を取得する(使用日の新しい順)。 */
export function fetchPaidLeaveUsagesForUser(userId: string): Promise<PaidLeaveUsage[]> {
  return apiFetch(`/paid-leave/usages/user/${userId}`)
}

/** 管理者による有給申請の取消(本人以外も対象)。承認済み(approved)の申請のみ取消可能で、
 *  紐づく消化記録(usage)をすべて一括で取り消す。 */
export function adminCancelPaidLeaveRequest(requestId: string): Promise<PaidLeaveRequest> {
  return apiFetch(`/paid-leave/requests/${requestId}/admin-cancel`, { method: 'POST' })
}

/** UC-P007: 自分の有給履歴(付与・申請・承認・差戻し・取消・消化)を新しい順に取得する。 */
export function fetchMyPaidLeaveHistory(): Promise<StoredEvent[]> {
  return apiFetch('/paid-leave/history/mine')
}

/** UC-P007: 管理者・人事担当者が対象社員の有給履歴を取得する。 */
export function fetchPaidLeaveHistoryForUser(userId: string): Promise<StoredEvent[]> {
  return apiFetch(`/paid-leave/history/user/${userId}`)
}
