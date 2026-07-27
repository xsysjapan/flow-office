import { apiFetch } from './client'
import type { Paginated, User, UserSearchResult } from './types'

/** role:admin,hr_staff限定。入社日・退社日・雇用区分・ロールを含む一覧が必要な管理画面向け。 */
export function fetchUsers(query?: string, perPage?: number): Promise<Paginated<User>> {
  return apiFetch('/users', { query: { q: query, per_page: perPage } })
}

/** 承認者選択(UserPicker)等、一般社員も使う軽量な検索。機微な項目は返らない。 */
export function searchUsers(query?: string, perPage?: number): Promise<Paginated<UserSearchResult>> {
  return apiFetch('/users/search', { query: { q: query, per_page: perPage } })
}

export function fetchUser(id: string): Promise<User> {
  return apiFetch(`/users/${id}`)
}

/** UC-M001: 権限を設定する。 */
export function updateUserRoles(id: string, roleCodes: string[]): Promise<User> {
  return apiFetch(`/users/${id}/roles`, { method: 'PUT', body: { role_codes: roleCodes } })
}

/** UC-P002: 有給の自動付与に使う継続勤務期間の基準日として入社日を設定する。 */
export function updateUserHireDate(id: string, hireDate: string): Promise<User> {
  return apiFetch(`/users/${id}/hire-date`, { method: 'PUT', body: { hire_date: hireDate } })
}

export function updateUserTerminationDate(id: string, terminationDate: string | null): Promise<User> {
  return apiFetch(`/users/${id}/termination-date`, { method: 'PUT', body: { termination_date: terminationDate } })
}

/** 勤怠提出フォロー等の各種フォロー通知の起算日となる利用開始日を設定する。 */
export function updateUserUsageStartDate(id: string, usageStartDate: string): Promise<User> {
  return apiFetch(`/users/${id}/usage-start-date`, { method: 'PUT', body: { usage_start_date: usageStartDate } })
}
