import { apiFetch } from './client'
import type {
  HolidayCalendarSource,
  Paginated,
  WeekdayHolidayPattern,
  WorkCalendar,
  WorkCalendarDay,
  WorkCalendarYear,
} from './types'

export function fetchWorkCalendars(): Promise<WorkCalendar[]> {
  return apiFetch('/company-calendars')
}

export interface FetchWorkCalendarsPageParams {
  page: number
  per_page?: number
}

/**
 * 一覧画面(WorkCalendarListPage)専用のページネーション付き取得。`fetchWorkCalendars`
 * (クエリパラメータ無し)は他画面が配列前提で使っているため、挙動を変えずこちらを別関数として追加する。
 */
export function fetchWorkCalendarsPage({
  page,
  per_page,
}: FetchWorkCalendarsPageParams): Promise<Paginated<WorkCalendar>> {
  return apiFetch('/company-calendars', { query: { page, per_page } })
}

export function deleteWorkCalendar(id: string): Promise<void> {
  return apiFetch(`/company-calendars/${id}`, { method: 'DELETE' })
}

export interface CreateWorkCalendarInput {
  name: string
  week_starts_on?: number
  fiscal_year_start_month?: number
  fiscal_year_start_day?: number
  weekday_holiday_pattern?: WeekdayHolidayPattern
  holiday_calendar_source_id?: string | null
  /** 省略時はbackend側の既定値(true)が使われる。 */
  allow_daily_holiday_override?: boolean
}

/**
 * 会社カレンダー本体のみを作成する(UC-C009手順1)。最初のカレンダー年度は
 * `WorkCalendarYearsPage`から個別に作成するか、UC-C011「今すぐ生成する」/UC-C014の
 * 定期バッチによって`fiscal_year_start_month`/`fiscal_year_start_day`から自動生成される。
 */
export function createWorkCalendar(input: CreateWorkCalendarInput): Promise<WorkCalendar> {
  return apiFetch('/company-calendars', { method: 'POST', body: input })
}

export interface UpdateWorkCalendarInput {
  name: string
  week_starts_on?: number
  fiscal_year_start_month?: number
  fiscal_year_start_day?: number
  holiday_calendar_source_id?: string | null
  weekday_holiday_pattern?: WeekdayHolidayPattern
  /** 省略時は現在値が維持される。 */
  allow_daily_holiday_override?: boolean
}

/**
 * 会社カレンダー本体の名称・週起算曜日・年度開始月日・祝日iCalendarソースを編集する
 * (作成時は名称のみを入力し、これらの設定は作成後にこのAPIで入力・変更する運用)。
 */
export function updateWorkCalendar(id: string, input: UpdateWorkCalendarInput): Promise<WorkCalendar> {
  return apiFetch(`/company-calendars/${id}`, { method: 'PUT', body: input })
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

/**
 * カレンダー年度を削除する(旧「廃止する」を置き換える操作。廃止はステータスを変えるだけで
 * 同じ年度番号を作り直せなかったため、実際に削除して同じ年度を再作成できるようにする)。
 */
export function deleteWorkCalendarYear(id: string): Promise<void> {
  return apiFetch(`/company-calendar-years/${id}`, { method: 'DELETE' })
}

/**
 * UC-C012: カレンダー年度1件分の期間だけを対象に祝日iCalendarソースと同期する
 * (`syncHolidayCalendarSource`はカレンダー本体配下の全年度を一括同期するのに対し、
 * こちらはこの年度の期間のみを同期する)。カレンダーに`holiday_calendar_source_id`が
 * 設定されていない場合は422が返る。
 */
export function syncCompanyCalendarYearHolidayCalendar(yearId: string): Promise<HolidayCalendarSource> {
  return apiFetch(`/company-calendar-years/${yearId}/sync-holiday-calendar`, { method: 'POST' })
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

/**
 * カレンダー年度を`draft`状態のときだけ再作成する。カレンダー本体の現在の曜日ごとの休日設定から
 * 全日を作り直し(手動での日別編集は破棄される)、祝日iCalendarソースが割り当てられていれば
 * この年度の期間だけ再同期する。`published`/`archived`の年度に対しては422が返る。
 */
export function regenerateCompanyCalendarYear(yearId: string): Promise<WorkCalendarDay[]> {
  return apiFetch(`/company-calendar-years/${yearId}/regenerate`, { method: 'POST' })
}

/**
 * UC-C010: カレンダー年度の日別属性(勤務区分・祝日)の既存登録内容を取得する。
 * 新規作成された年度は週次パターンからの初期値で自動的に埋まっているため
 * (`WorkCalendarDaysPage`参照)、日別編集画面はこのAPIで現状を読み込んでから編集させる。
 */
export function fetchCompanyCalendarYearDays(companyCalendarYearId: string): Promise<WorkCalendarDay[]> {
  return apiFetch(`/company-calendar-years/${companyCalendarYearId}/days`)
}
