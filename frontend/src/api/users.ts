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

/** UC-P002: 有給の自動付与に使う継続勤務期間の基準日として入社日を設定する。 */
export function updateUserHireDate(id: number, hireDate: string): Promise<User> {
  return apiFetch(`/users/${id}/hire-date`, { method: 'PUT', body: { hire_date: hireDate } })
}
