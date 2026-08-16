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
