import { apiFetch } from './client'
import type { EmployeeRotationAssignment, EmployeeShiftAssignment } from './types'

export function fetchEmployeeRotationAssignment(userId: string): Promise<EmployeeRotationAssignment | null> {
  return apiFetch('/employee-rotation-assignments', { query: { user_id: userId } })
}

export interface AssignEmployeeRotationInput {
  user_id: string
  rotation_pattern_id: number
  rotation_start_date: string
  rotation_start_position: number
}

export function assignEmployeeRotation(input: AssignEmployeeRotationInput): Promise<EmployeeRotationAssignment> {
  return apiFetch('/employee-rotation-assignments', { method: 'POST', body: input })
}

export type RotationOverwriteMode = 'skip_edited' | 'overwrite_all'

export interface GenerateRotationShiftAssignmentsInput {
  user_id: string
  from: string
  to: string
  overwrite_mode?: RotationOverwriteMode
}

export interface GenerateRotationShiftAssignmentsResult {
  generated: EmployeeShiftAssignment[]
  generated_count: number
  skipped_dates: string[]
}

export function generateRotationShiftAssignments(
  input: GenerateRotationShiftAssignmentsInput,
): Promise<GenerateRotationShiftAssignmentsResult> {
  return apiFetch('/employee-rotation-assignments/generate', { method: 'POST', body: input })
}
