import { apiFetch } from './client'
import type { BackOfficeTask, BackOfficeTaskStatus, Paginated } from './types'

export function fetchUnassignedTasks(): Promise<Paginated<BackOfficeTask>> {
  return apiFetch('/backoffice-tasks/unassigned')
}

export function fetchMyTasks(): Promise<Paginated<BackOfficeTask>> {
  return apiFetch('/backoffice-tasks/mine')
}

export function fetchBackOfficeTask(id: number): Promise<BackOfficeTask> {
  return apiFetch(`/backoffice-tasks/${id}`)
}

export function assignBackOfficeTask(id: number, assignedUserId: number): Promise<BackOfficeTask> {
  return apiFetch(`/backoffice-tasks/${id}/assign`, {
    method: 'POST',
    body: { assigned_user_id: assignedUserId },
  })
}

export function changeBackOfficeTaskStatus(
  id: number,
  status: BackOfficeTaskStatus,
  comment?: string,
): Promise<BackOfficeTask> {
  return apiFetch(`/backoffice-tasks/${id}/status`, {
    method: 'POST',
    body: { status, comment },
  })
}
