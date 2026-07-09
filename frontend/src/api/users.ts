import { apiFetch } from './client'
import type { Paginated, User } from './types'

export function fetchUsers(query?: string): Promise<Paginated<User>> {
  return apiFetch('/users', { query: { q: query } })
}

export function fetchUser(id: number): Promise<User> {
  return apiFetch(`/users/${id}`)
}

/** UC-M001: 権限を設定する。 */
export function updateUserRoles(id: number, roleCodes: string[]): Promise<User> {
  return apiFetch(`/users/${id}/roles`, { method: 'PUT', body: { role_codes: roleCodes } })
}
