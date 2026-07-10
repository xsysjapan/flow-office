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
  requires_backoffice_task?: boolean
  backoffice_task_type?: string
  is_active?: boolean
}

export function createRequestType(input: SaveRequestTypeInput): Promise<RequestType> {
  return apiFetch('/request-types', { method: 'POST', body: input })
}

export function updateRequestType(id: number, input: SaveRequestTypeInput): Promise<RequestType> {
  return apiFetch(`/request-types/${id}`, { method: 'PUT', body: input })
}
