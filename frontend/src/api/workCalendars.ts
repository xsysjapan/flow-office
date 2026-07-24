import { apiFetch } from './client'
import type { WorkCalendar, WorkCalendarDay } from './types'

export function fetchWorkCalendars(): Promise<WorkCalendar[]> {
  return apiFetch('/work-calendars')
}

export interface CreateWorkCalendarInput {
  name: string
  fiscal_year: number
  starts_on: string
  ends_on: string
  week_starts_on?: number
}

export function createWorkCalendar(input: CreateWorkCalendarInput): Promise<WorkCalendar> {
  return apiFetch('/work-calendars', { method: 'POST', body: input })
}

export function publishWorkCalendar(id: string): Promise<WorkCalendar> {
  return apiFetch(`/work-calendars/${id}/publish`, { method: 'POST' })
}

export interface PutCalendarDayInput {
  date: string
  day_type: string
  is_working_day?: boolean
  is_legal_holiday?: boolean
  is_company_holiday?: boolean
  note?: string
}

export function putWorkCalendarDays(id: string, days: PutCalendarDayInput[]): Promise<WorkCalendarDay[]> {
  return apiFetch(`/work-calendars/${id}/days`, { method: 'PUT', body: { days } })
}
