import { apiFetch } from './client'
import type { RequestFormFieldSchema, RequestType } from './types'

export function fetchRequestTypes(includeInactive = false): Promise<RequestType[]> {
  return apiFetch('/request-types', { query: { include_inactive: includeInactive || undefined } })
}

export interface SaveRequestTypeInput {
  code: string
  name: string
  description?: string
  form_schema: RequestFormFieldSchema[]
  requires_attachment?: boolean
  attachment_max_size_kb?: number
  attachment_allowed_extensions?: string[]
  eligible_role_codes?: string[]
  requires_backoffice_task?: boolean
  backoffice_task_type?: string
  backoffice_department?: string
  export_amount_field?: string
  allowed_status_transitions?: Record<string, string[]>
  is_active?: boolean
}

export function createRequestType(input: SaveRequestTypeInput): Promise<RequestType> {
  return apiFetch('/request-types', { method: 'POST', body: input })
}

export function updateRequestType(id: number, input: SaveRequestTypeInput): Promise<RequestType> {
  return apiFetch(`/request-types/${id}`, { method: 'PUT', body: input })
}
