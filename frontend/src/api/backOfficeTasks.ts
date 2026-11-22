import { apiFetch } from './client'
import type {
  BackOfficeTask,
  BackOfficeTaskAssigneeScope,
  BackOfficeTaskSearchResult,
  BackOfficeTaskStatus,
  BackOfficeTaskStatusFilter,
} from './types'

export interface BackOfficeTaskListOptions {
  search?: string
  assignee?: BackOfficeTaskAssigneeScope
  status?: BackOfficeTaskStatusFilter
  page?: number
  perPage?: number
}

export function fetchBackOfficeTasks(options: BackOfficeTaskListOptions = {}): Promise<BackOfficeTaskSearchResult> {
  return apiFetch('/backoffice-tasks', {
    query: {
      search: options.search,
      assignee: options.assignee,
      status: options.status,
      page: options.page,
      per_page: options.perPage,
    },
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
