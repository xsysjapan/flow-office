import { apiFetch } from './client'
import type { UserWorkStyleMonthlyAssignment } from './types'

export function fetchUserWorkStyleMonthlyAssignments(userId: string): Promise<UserWorkStyleMonthlyAssignment[]> {
  return apiFetch('/user-work-style-monthly-assignments', { query: { user_id: userId } })
}

export interface AssignUserWorkStyleForMonthInput {
  user_id: string
  year_month: string
  work_style_id: number
}

export function assignUserWorkStyleForMonth(
  input: AssignUserWorkStyleForMonthInput,
): Promise<UserWorkStyleMonthlyAssignment> {
  return apiFetch('/user-work-style-monthly-assignments', { method: 'POST', body: input })
}

/** 指示書 13章: 個別の働き方指定を取り消し、「会社のデフォルトを使用」の状態に戻す。 */
export function removeUserWorkStyleMonthlyAssignment(id: number): Promise<void> {
  return apiFetch(`/user-work-style-monthly-assignments/${id}`, { method: 'DELETE' })
}
