import { apiFetch } from './client'
import type { Role } from './types'

export function fetchRoles(): Promise<Role[]> {
  return apiFetch('/roles')
}
