import { apiFetch } from './client'
import type { HolidayCalendarSource } from './types'

/** UC-C012: 祝日iCalendarソースの一覧取得・登録・同期・無効化・直前同期の取消。 */
export interface CreateHolidayCalendarSourceInput {
  name: string
  ics_url: string
}

export function fetchHolidayCalendarSources(): Promise<HolidayCalendarSource[]> {
  return apiFetch('/holiday-calendar-sources')
}

export function createHolidayCalendarSource(
  input: CreateHolidayCalendarSourceInput,
): Promise<HolidayCalendarSource> {
  return apiFetch('/holiday-calendar-sources', { method: 'POST', body: input })
}

export function syncHolidayCalendarSource(id: string): Promise<HolidayCalendarSource> {
  return apiFetch(`/holiday-calendar-sources/${id}/sync`, { method: 'POST' })
}

export function disableHolidayCalendarSource(id: string): Promise<HolidayCalendarSource> {
  return apiFetch(`/holiday-calendar-sources/${id}/disable`, { method: 'POST' })
}

/** UC-C012 手順4後半: 直近1回分の祝日同期を取消す。 */
export function revertLastHolidayCalendarSync(id: string): Promise<HolidayCalendarSource> {
  return apiFetch(`/holiday-calendar-sources/${id}/revert-last-sync`, { method: 'POST' })
}
