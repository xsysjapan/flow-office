import { apiFetch } from './client'
import type { Paginated, WorkflowRequest, WorkflowRequestHistoryEntry, WorkflowRequestStatus, WorkflowRequestSubjectType } from './types'

export interface FetchMyWorkflowRequestsOptions {
  status?: WorkflowRequestStatus | 'all'
  subjectType?: WorkflowRequestSubjectType | 'all'
  page?: number
  perPage?: number
}

/** UC-W002: 申請センター(自分の申請の横断一覧)。status・subject_type・ページングで
 *  絞り込める(いずれも省略時はバックエンド既定=絞り込みなしの全件)。 */
export function fetchMyWorkflowRequests(options: FetchMyWorkflowRequestsOptions = {}): Promise<Paginated<WorkflowRequest>> {
  const { status, subjectType, page, perPage } = options
  return apiFetch('/workflow-requests/mine', {
    query: {
      status: status && status !== 'all' ? status : undefined,
      subject_type: subjectType && subjectType !== 'all' ? subjectType : undefined,
      page,
      per_page: perPage,
    },
  })
}

export interface FetchWorkflowRequestsToApproveOptions {
  status?: WorkflowRequestStatus | 'all'
  yearMonth?: string
  page?: number
  perPage?: number
}

/** UC-W004: 承認待ち申請一覧。既定は承認待ち(submitted)のみだが、ステータス
 *  ('all'を渡すと絞り込みなし)・年月での絞り込みとページングに対応する。 */
export function fetchWorkflowRequestsToApprove(options: FetchWorkflowRequestsToApproveOptions = {}): Promise<Paginated<WorkflowRequest>> {
  const { status, yearMonth, page, perPage } = options
  return apiFetch('/workflow-requests/to-approve', {
    query: { status, year_month: yearMonth, page, per_page: perPage },
  })
}

export function fetchWorkflowRequest(id: string): Promise<WorkflowRequest> {
  return apiFetch(`/workflow-requests/${id}`)
}

export interface CreateWorkflowRequestInput {
  request_type_code: string
  title: string
  form_data: Record<string, unknown>
  approver_user_id?: string
}

export function createWorkflowRequest(input: CreateWorkflowRequestInput): Promise<WorkflowRequest> {
  return apiFetch('/workflow-requests', { method: 'POST', body: input })
}

export function submitWorkflowRequest(id: string, approverUserId?: string): Promise<WorkflowRequest> {
  return apiFetch(`/workflow-requests/${id}/submit`, {
    method: 'POST',
    body: { approver_user_id: approverUserId },
  })
}

export function approveWorkflowRequest(id: string): Promise<WorkflowRequest> {
  return apiFetch(`/workflow-requests/${id}/approve`, { method: 'POST' })
}

export function returnWorkflowRequest(id: string, comment: string): Promise<WorkflowRequest> {
  return apiFetch(`/workflow-requests/${id}/return`, { method: 'POST', body: { comment } })
}

export function cancelWorkflowRequest(id: string, reason: string): Promise<WorkflowRequest> {
  return apiFetch(`/workflow-requests/${id}/cancel`, { method: 'POST', body: { reason } })
}

/** UC-W003/UC-W004 コメント履歴。 */
export function fetchWorkflowRequestHistory(id: string): Promise<WorkflowRequestHistoryEntry[]> {
  return apiFetch(`/workflow-requests/${id}/history`)
}
