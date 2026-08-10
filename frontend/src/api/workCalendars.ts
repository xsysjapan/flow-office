import { apiFetch } from './client'
import type { WorkCalendar, WorkCalendarDay, WorkCalendarYear } from './types'

export function fetchWorkCalendars(): Promise<WorkCalendar[]> {
  return apiFetch('/company-calendars')
}

export interface CreateWorkCalendarInput {
  name: string
  week_starts_on?: number
  fiscal_year_start_month?: number
  fiscal_year_start_day?: number
  // 本体作成に続けて最初のカレンダー年度も作成する(UC-C009 手順1〜2)。
  fiscal_year: number
  starts_on: string
  ends_on: string
}

export function createWorkCalendar(input: CreateWorkCalendarInput): Promise<WorkCalendar> {
  return apiFetch('/company-calendars', { method: 'POST', body: input })
}

export function fetchWorkCalendarYears(companyCalendarId: string): Promise<WorkCalendarYear[]> {
  return apiFetch(`/company-calendars/${companyCalendarId}/years`)
}

export interface CreateWorkCalendarYearInput {
  fiscal_year: number
  starts_on: string
  ends_on: string
}

export function createWorkCalendarYear(
  companyCalendarId: string,
  input: CreateWorkCalendarYearInput,
): Promise<WorkCalendarYear> {
  return apiFetch(`/company-calendars/${companyCalendarId}/years`, { method: 'POST', body: input })
}

export function publishWorkCalendarYear(id: string): Promise<WorkCalendarYear> {
  return apiFetch(`/company-calendar-years/${id}/publish`, { method: 'POST' })
}

export function unpublishWorkCalendarYear(id: string): Promise<WorkCalendarYear> {
  return apiFetch(`/company-calendar-years/${id}/unpublish`, { method: 'POST' })
}

export function archiveWorkCalendarYear(id: string): Promise<WorkCalendarYear> {
  return apiFetch(`/company-calendar-years/${id}/archive`, { method: 'POST' })
}

export interface PutCalendarDayInput {
  date: string
  day_type: string
  is_working_day?: boolean
  is_legal_holiday?: boolean
  is_company_holiday?: boolean
  is_public_holiday?: boolean
  public_holiday_name?: string | null
  schedule_state?: 'WORK' | 'OFF'
  note?: string
}

export function putWorkCalendarDays(
  companyCalendarYearId: string,
  days: PutCalendarDayInput[],
): Promise<WorkCalendarDay[]> {
  return apiFetch(`/company-calendar-years/${companyCalendarYearId}/days`, { method: 'PUT', body: { days } })
}
