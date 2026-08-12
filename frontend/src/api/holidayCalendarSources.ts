import { apiFetch } from './client'
import type { HolidayCalendarSource } from './types'

/** UC-C012: 祝日iCalendarソースの一覧取得・登録・更新・同期・無効化・直前同期の取消。 */
export interface HolidayCalendarSourceInput {
  name: string
  /** ics_url / ics_file のどちらか一方のみを指定する。 */
  ics_url?: string
  ics_file?: File
}

function buildHolidayCalendarSourceFormData(input: HolidayCalendarSourceInput): FormData {
  const formData = new FormData()
  formData.append('name', input.name)
  if (input.ics_url !== undefined) {
    formData.append('ics_url', input.ics_url)
  }
  if (input.ics_file !== undefined) {
    formData.append('ics_file', input.ics_file)
  }
  return formData
}

export function fetchHolidayCalendarSources(): Promise<HolidayCalendarSource[]> {
  return apiFetch('/holiday-calendar-sources')
}

export function createHolidayCalendarSource(
  input: HolidayCalendarSourceInput,
): Promise<HolidayCalendarSource> {
  return apiFetch('/holiday-calendar-sources', {
    method: 'POST',
    body: buildHolidayCalendarSourceFormData(input),
  })
}

export function updateHolidayCalendarSource(
  id: string,
  input: HolidayCalendarSourceInput,
): Promise<HolidayCalendarSource> {
  return apiFetch(`/holiday-calendar-sources/${id}`, {
    method: 'POST',
    body: buildHolidayCalendarSourceFormData(input),
  })
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
