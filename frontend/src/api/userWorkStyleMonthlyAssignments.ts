import { apiFetch } from './client'
import type { UserWorkStyleMonthlyAssignment } from './types'

export function fetchUserWorkStyleMonthlyAssignments(userId: number): Promise<UserWorkStyleMonthlyAssignment[]> {
  return apiFetch('/user-work-style-monthly-assignments', { query: { user_id: userId } })
}

export interface AssignUserWorkStyleForMonthInput {
  user_id: number
  year_month: string
  work_style_id: number
}

export function assignUserWorkStyleForMonth(
  input: AssignUserWorkStyleForMonthInput,
): Promise<UserWorkStyleMonthlyAssignment> {
  return apiFetch('/user-work-style-monthly-assignments', { method: 'POST', body: input })
}
