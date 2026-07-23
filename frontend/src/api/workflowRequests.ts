import { apiFetch } from './client'
import type { Paginated, WorkflowRequest, WorkflowRequestHistoryEntry } from './types'

export function fetchMyWorkflowRequests(): Promise<Paginated<WorkflowRequest>> {
  return apiFetch('/workflow-requests/mine')
}

export function fetchWorkflowRequestsToApprove(): Promise<Paginated<WorkflowRequest>> {
  return apiFetch('/workflow-requests/to-approve')
}

export function fetchWorkflowRequest(id: string): Promise<WorkflowRequest> {
  return apiFetch(`/workflow-requests/${id}`)
}

export interface CreateWorkflowRequestInput {
  request_type_code: string
  title: string
  form_data: Record<string, unknown>
  approver_user_id?: number
}

export function createWorkflowRequest(input: CreateWorkflowRequestInput): Promise<WorkflowRequest> {
  return apiFetch('/workflow-requests', { method: 'POST', body: input })
}

export function submitWorkflowRequest(id: string, approverUserId?: number): Promise<WorkflowRequest> {
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
