import { apiFetch } from './client'
import type { RequestType } from './types'

export function fetchRequestTypes(): Promise<RequestType[]> {
  return apiFetch('/request-types')
}
