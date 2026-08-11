import { apiFetch } from './client'
import type { HolidayCalendarSource } from './types'

/**
 * UC-C012: 祝日iCalendarソースの登録・同期・無効化。backendには一覧取得(index)エンドポイントが
 * 無いため、一覧はフロント側でこれらのAPI呼び出しの結果を積み上げて表示する
 * (`hooks/useHolidayCalendarSources.ts`参照)。
 */
export interface CreateHolidayCalendarSourceInput {
  name: string
  ics_url: string
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
