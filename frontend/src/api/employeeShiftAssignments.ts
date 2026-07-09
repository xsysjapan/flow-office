import { apiFetch } from './client'
import type { EmployeeShiftAssignment } from './types'

export function fetchShiftAssignments(userId: number, from: string, to: string): Promise<EmployeeShiftAssignment[]> {
  return apiFetch('/employee-shift-assignments', { query: { user_id: userId, from, to } })
}

export interface GenerateShiftAssignmentsInput {
  user_id: number
  work_style_id: number
  from: string
  to: string
}

export function generateShiftAssignments(input: GenerateShiftAssignmentsInput): Promise<EmployeeShiftAssignment[]> {
  return apiFetch('/employee-shift-assignments/generate', { method: 'POST', body: input })
}
