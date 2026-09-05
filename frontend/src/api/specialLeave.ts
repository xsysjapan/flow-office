import { apiFetch } from './client'
import type {
  PaidLeaveType,
  SpecialLeaveGrant,
  SpecialLeaveGrantRule,
  SpecialLeaveGrantRuleTargetUser,
  SpecialLeaveRequest,
  SpecialLeaveType,
  SpecialLeaveUsage,
  StoredEvent,
} from './types'

export function fetchSpecialLeaveTypes(): Promise<SpecialLeaveType[]> {
  return apiFetch('/special-leave/types')
}

export interface CreateSpecialLeaveTypeInput {
  name: string
  is_active?: boolean
  /** falseの場合、事前の付与(残数)が無くても申請できる(忌引・代休等)。省略時はtrue。 */
  requires_grant?: boolean
}

export function createSpecialLeaveType(input: CreateSpecialLeaveTypeInput): Promise<SpecialLeaveType> {
  return apiFetch('/special-leave/types', { method: 'POST', body: input })
}

export interface UpdateSpecialLeaveTypeInput {
  name: string
  is_active?: boolean
  requires_grant?: boolean
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

/** ルールの対象条件(雇用形態/勤務体系)にマッチする社員の軽量一覧を取得する(対象社員セクション用)。 */
export function fetchSpecialLeaveGrantRuleTargetUsers(ruleId: number): Promise<SpecialLeaveGrantRuleTargetUser[]> {
  return apiFetch(`/special-leave/grant-rules/${ruleId}/target-users`)
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

/** 使用日数が0の付与のみ取消可能(422で拒否される場合がある)。 */
export function revokeSpecialLeaveGrant(grantId: string, reason?: string): Promise<SpecialLeaveGrant> {
  return apiFetch(`/special-leave/grants/${grantId}/revoke`, { method: 'POST', body: { reason } })
}

export function fetchMySpecialLeaveRequests(): Promise<SpecialLeaveRequest[]> {
  return apiFetch('/special-leave/requests/mine')
}

export interface CreateSpecialLeaveRequestInput {
  special_leave_type_id: number
  target_date: string
  leave_type: PaidLeaveType
  hours?: number
  /** system_settings.special_leave_requires_approval が false の場合は省略可(承認不要)。 */
  approver_user_id?: string
  reason?: string
  /** 複数日をまとめて申請する場合、同一の申請操作内で生成した同じ値を全日分に渡す
   *  (承認者は対応するworkflow_requestのいずれか1件を承認するだけで、この値を共有する
   *  他の申請もまとめて承認できるようになる)。単日申請では省略する。 */
  request_group_id?: string
}

export function createSpecialLeaveRequest(input: CreateSpecialLeaveRequestInput): Promise<SpecialLeaveRequest> {
  return apiFetch('/special-leave/requests', { method: 'POST', body: input })
}

export function cancelSpecialLeaveRequest(id: string): Promise<SpecialLeaveRequest> {
  return apiFetch(`/special-leave/requests/${id}/cancel`, { method: 'POST' })
}

/** 管理者・人事担当者が対象社員の特別休暇消化記録(usage)一覧を取得する(使用日の新しい順)。 */
export function fetchSpecialLeaveUsagesForUser(userId: string): Promise<SpecialLeaveUsage[]> {
  return apiFetch(`/special-leave/usages/user/${userId}`)
}

/** 管理者による特別休暇申請の取消(本人以外も対象)。承認済み(approved)の申請のみ取消可能で、
 *  紐づく消化記録(usage)をすべて一括で取り消す。 */
export function adminCancelSpecialLeaveRequest(requestId: string): Promise<SpecialLeaveRequest> {
  return apiFetch(`/special-leave/requests/${requestId}/admin-cancel`, { method: 'POST' })
}

/** 自分の特別休暇履歴(付与・申請・承認・差戻し・取消・消化)を新しい順に取得する。 */
export function fetchMySpecialLeaveHistory(): Promise<StoredEvent[]> {
  return apiFetch('/special-leave/history/mine')
}

/** 管理者・人事担当者が対象社員の特別休暇履歴を取得する。 */
export function fetchSpecialLeaveHistoryForUser(userId: string): Promise<StoredEvent[]> {
  return apiFetch(`/special-leave/history/user/${userId}`)
}
