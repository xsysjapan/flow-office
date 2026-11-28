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

/** 一括操作(全部か無しか)の成功の応答の1件。`skipped`は処理不要で飛ばした件(完了済みなど)。 */
export interface BackOfficeBulkResultItem {
  id: string
  result: 'processed' | 'skipped'
}

/** 一括完了(全部か無しか)。1件でも失敗すると422(`bulk_operation_rejected`)で何も完了しない。 */
export function bulkCompleteBackOfficeTasks(taskIds: string[]): Promise<{ data: BackOfficeBulkResultItem[] }> {
  return apiFetch('/backoffice-tasks/bulk-complete', {
    method: 'POST',
    body: { task_ids: taskIds },
  })
}

/** 担当者の一括変更(全部か無しか)。1件でも失敗すると422(`bulk_operation_rejected`)で何も変更しない。 */
export function bulkAssignBackOfficeTasks(
  taskIds: string[],
  assignedUserId: string,
): Promise<{ data: BackOfficeBulkResultItem[] }> {
  return apiFetch('/backoffice-tasks/bulk-assign', {
    method: 'POST',
    body: { task_ids: taskIds, assigned_user_id: assignedUserId },
  })
}
