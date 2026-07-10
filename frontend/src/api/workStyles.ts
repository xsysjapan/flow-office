import { apiFetch } from './client'
import type { WorkStyle } from './types'

export function fetchWorkStyles(): Promise<WorkStyle[]> {
  return apiFetch('/work-styles')
}

export interface CreateWorkStyleInput {
  code: string
  name: string
  work_time_system: string
  prescribed_daily_minutes: number
  prescribed_weekly_minutes: number
  default_start_time?: string
  default_end_time?: string
  default_break_minutes?: number
  calendar_id: number
  is_shift_based?: boolean
}

export function createWorkStyle(input: CreateWorkStyleInput): Promise<WorkStyle> {
  return apiFetch('/work-styles', { method: 'POST', body: input })
}
