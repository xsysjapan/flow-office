import { apiFetch } from './client'
import type { ShiftPattern } from './types'

export function fetchShiftPatterns(): Promise<ShiftPattern[]> {
  return apiFetch('/shift-patterns')
}

export interface ShiftPatternInput {
  code: string
  name: string
  start_time?: string
  end_time?: string
  crosses_midnight?: boolean
  break_minutes?: number
  break_start_time?: string
  break_end_time?: string
  prescribed_work_minutes?: number
}

export function createShiftPattern(input: ShiftPatternInput): Promise<ShiftPattern> {
  return apiFetch('/shift-patterns', { method: 'POST', body: input })
}

export function updateShiftPattern(id: number, input: Omit<ShiftPatternInput, 'code'>): Promise<ShiftPattern> {
  return apiFetch(`/shift-patterns/${id}`, { method: 'PUT', body: input })
}
