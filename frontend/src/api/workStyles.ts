import { apiFetch } from './client'
import type { LegalHolidayRule, WorkStyle } from './types'

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
  rounding_unit_minutes?: number
  default_break_start_time?: string
  default_break_end_time?: string
  auto_break_enabled?: boolean
  calendar_id: string
  is_shift_based?: boolean
  legal_holiday_rule?: LegalHolidayRule
  four_week_period_start_date?: string
  max_consecutive_work_days?: number
  settlement_start_day?: number
  core_time_enabled?: boolean
  core_time_start?: string
  core_time_end?: string
  flexible_time_start?: string
  flexible_time_end?: string
}

export function createWorkStyle(input: CreateWorkStyleInput): Promise<WorkStyle> {
  return apiFetch('/work-styles', { method: 'POST', body: input })
}

export interface CreateDefaultWorkStyleInput {
  name?: string
  default_start_time?: string
  default_end_time?: string
  default_break_minutes?: number
  default_break_start_time?: string
  default_break_end_time?: string
  auto_break_enabled?: boolean
  calendar_id?: string
}

/** 初回オンボーディングで「通常勤務」をデフォルト働き方として作成する(指示書 3.1節・12.1節)。 */
export function createDefaultWorkStyle(input: CreateDefaultWorkStyleInput = {}): Promise<WorkStyle> {
  return apiFetch('/work-styles/default', { method: 'POST', body: input })
}

/** 既存の働き方を会社のデフォルトに切り替える(指示書 3.2節)。 */
export function setDefaultWorkStyle(id: string): Promise<WorkStyle> {
  return apiFetch(`/work-styles/${id}/set-default`, { method: 'POST' })
}
