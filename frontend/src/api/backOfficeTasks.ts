import { apiFetch } from './client'
import type { BackOfficeTask, BackOfficeTaskStatus, Paginated } from './types'

export interface BackOfficeTaskListOptions {
  search?: string
  page?: number
  per_page?: number
}

export function fetchUnassignedTasks(options: BackOfficeTaskListOptions = {}): Promise<Paginated<BackOfficeTask>> {
  return apiFetch('/backoffice-tasks/unassigned', {
    query: { search: options.search, page: options.page, per_page: options.per_page },
  })
}

export function fetchMyTasks(options: BackOfficeTaskListOptions = {}): Promise<Paginated<BackOfficeTask>> {
  return apiFetch('/backoffice-tasks/mine', {
    query: { search: options.search, page: options.page, per_page: options.per_page },
  })
}

export function fetchBackOfficeTask(id: string): Promise<BackOfficeTask> {
  return apiFetch(`/backoffice-tasks/${id}`)
}

export function assignBackOfficeTask(id: string, assignedUserId: string): Promise<BackOfficeTask> {
  return apiFetch(`/backoffice-tasks/${id}/assign`, {
    method: 'POST',
    body: { assigned_user_id: assignedUserId },
  })
}

export function changeBackOfficeTaskStatus(
  id: string,
  status: BackOfficeTaskStatus,
  comment?: string,
): Promise<BackOfficeTask> {
  return apiFetch(`/backoffice-tasks/${id}/status`, {
    method: 'POST',
    body: { status, comment },
  })
}

export function bulkCompleteBackOfficeTasks(taskIds: string[]): Promise<BackOfficeTask[]> {
  return apiFetch('/backoffice-tasks/bulk-complete', {
    method: 'POST',
    body: { task_ids: taskIds },
  })
}
