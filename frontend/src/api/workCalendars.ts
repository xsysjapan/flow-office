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
}

/**
 * 会社カレンダー本体のみを作成する(UC-C009手順1)。最初のカレンダー年度は
 * `WorkCalendarYearsPage`から個別に作成するか、UC-C011「今すぐ生成する」/UC-C014の
 * 定期バッチによって`fiscal_year_start_month`/`fiscal_year_start_day`から自動生成される。
 */
export function createWorkCalendar(input: CreateWorkCalendarInput): Promise<WorkCalendar> {
  return apiFetch('/company-calendars', { method: 'POST', body: input })
}

/**
 * docs/16-database-schema.md: 会社カレンダー本体で有効なデフォルトは常に高々1件。
 * 指定した本体をデフォルトに切り替える(既存のデフォルトはbackend側で解除される)。
 */
export function setDefaultWorkCalendar(id: string): Promise<WorkCalendar> {
  return apiFetch(`/company-calendars/${id}/set-default`, { method: 'POST' })
}

export function duplicateWorkCalendarYear(id: string): Promise<WorkCalendarYear> {
  return apiFetch(`/company-calendar-years/${id}/duplicate`, { method: 'POST' })
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

export interface GenerateCompanyCalendarYearsNowResult {
  generated_company_calendar_year_ids: string[]
}

/**
 * UC-C011「今すぐ生成する」: 全ての会社カレンダー本体について、
 * fiscal_year_start_month/dayから標準のカレンダー年度をまとめて生成する
 * (`OnboardingCalendarPage`から呼ぶ)。
 */
export function generateCompanyCalendarYearsNow(): Promise<GenerateCompanyCalendarYearsNowResult> {
  return apiFetch('/onboarding/calendar/generate-now', { method: 'POST' })
}
