import { apiFetch } from './client'
import type {
  CalendarBulkOperation,
  CalendarBulkOperationConflictPolicy,
  CalendarBulkOperationPreview,
  CalendarBulkOperationType,
} from './types'

/** UC-C013: 複数従業員予定の一括操作(プレビュー→確定適用→取消)。 */
export interface CalendarBulkOperationRequest {
  operation_type: CalendarBulkOperationType
  target_scope: Record<string, unknown>
  conflict_policy: CalendarBulkOperationConflictPolicy
  reason: string
}

export function previewCalendarBulkOperation(
  input: CalendarBulkOperationRequest,
): Promise<CalendarBulkOperationPreview> {
  return apiFetch('/calendar-bulk-operations/preview', { method: 'POST', body: input })
}

export function createCalendarBulkOperation(input: CalendarBulkOperationRequest): Promise<CalendarBulkOperation> {
  return apiFetch('/calendar-bulk-operations', { method: 'POST', body: input })
}

export function fetchCalendarBulkOperations(): Promise<CalendarBulkOperation[]> {
  return apiFetch('/calendar-bulk-operations')
}

export function fetchCalendarBulkOperation(id: string): Promise<CalendarBulkOperation> {
  return apiFetch(`/calendar-bulk-operations/${id}`)
}

export function revertCalendarBulkOperation(id: string): Promise<CalendarBulkOperation> {
  return apiFetch(`/calendar-bulk-operations/${id}/revert`, { method: 'POST' })
}
