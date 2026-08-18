import { apiFetch } from './client'
import type { CompensatoryLeaveGrant, CompensatoryLeaveRequest, CompensatoryLeaveUsage, PaidLeaveType, StoredEvent } from './types'

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
  /** 複数日をまとめて申請する場合、同一の申請操作内で生成した同じ値を全日分に渡す
   *  (承認者は対応するworkflow_requestのいずれか1件を承認するだけで、この値を共有する
   *  他の申請もまとめて承認できるようになる)。単日申請では省略する。 */
  request_group_id?: string
}

export function createCompensatoryLeaveRequest(input: CreateCompensatoryLeaveRequestInput): Promise<CompensatoryLeaveRequest> {
  return apiFetch('/compensatory-leave/requests', { method: 'POST', body: input })
}

export function cancelCompensatoryLeaveRequest(id: string): Promise<CompensatoryLeaveRequest> {
  return apiFetch(`/compensatory-leave/requests/${id}/cancel`, { method: 'POST' })
}

/** 管理者・人事担当者が対象社員の代休消化記録(usage)一覧を取得する(使用日の新しい順)。 */
export function fetchCompensatoryLeaveUsagesForUser(userId: string): Promise<CompensatoryLeaveUsage[]> {
  return apiFetch(`/compensatory-leave/usages/user/${userId}`)
}

/** 管理者による代休申請の取消(本人以外も対象)。承認済み(approved)の申請のみ取消可能で、
 *  紐づく消化記録(usage)をすべて一括で取り消す。 */
export function adminCancelCompensatoryLeaveRequest(requestId: string): Promise<CompensatoryLeaveRequest> {
  return apiFetch(`/compensatory-leave/requests/${requestId}/admin-cancel`, { method: 'POST' })
}

export interface GrantCompensatoryLeaveInput {
  user_id: string
  /** 休日出勤の実績日。この日が休日出勤でなければサーバー側で422になる。 */
  work_date: string
  expires_on?: string
  grant_reason?: string
}

/** 管理者による代休の手動付与。日数はサーバーが実績から導出するため入力しない。 */
export function grantCompensatoryLeave(input: GrantCompensatoryLeaveInput): Promise<CompensatoryLeaveGrant> {
  return apiFetch('/compensatory-leave/grants', { method: 'POST', body: input })
}

export function fetchCompensatoryLeaveGrantsForUser(userId: string): Promise<CompensatoryLeaveGrant[]> {
  return apiFetch(`/compensatory-leave/grants/user/${userId}`)
}

/** 使用日数が0の付与のみ取消可能(422で拒否される場合がある)。付与元(自動導出/手動)を問わず利用できる。 */
export function revokeCompensatoryLeaveGrant(grantId: string, reason?: string): Promise<CompensatoryLeaveGrant> {
  return apiFetch(`/compensatory-leave/grants/${grantId}/revoke`, { method: 'POST', body: { reason } })
}

/** 自分の代休履歴(付与・申請・承認・差戻し・取消・消化)を新しい順に取得する。 */
export function fetchMyCompensatoryLeaveHistory(): Promise<StoredEvent[]> {
  return apiFetch('/compensatory-leave/history/mine')
}

/** 管理者・人事担当者が対象社員の代休履歴を取得する。 */
export function fetchCompensatoryLeaveHistoryForUser(userId: string): Promise<StoredEvent[]> {
  return apiFetch(`/compensatory-leave/history/user/${userId}`)
}
