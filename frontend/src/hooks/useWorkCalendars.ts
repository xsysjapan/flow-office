import { useMutation, useQueries, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  archiveWorkCalendarYear,
  createWorkCalendar,
  createWorkCalendarYear,
  deleteWorkCalendar,
  duplicateWorkCalendarYear,
  fetchCompanyCalendarYearDays,
  fetchWorkCalendars,
  fetchWorkCalendarsPage,
  fetchWorkCalendarYears,
  publishWorkCalendarYear,
  putWorkCalendarDays,
  setDefaultWorkCalendar,
  syncCompanyCalendarYearHolidayCalendar,
  unpublishWorkCalendarYear,
  updateWorkCalendar,
  type CreateWorkCalendarInput,
  type CreateWorkCalendarYearInput,
  type PutCalendarDayInput,
  type UpdateWorkCalendarInput,
} from '../api/workCalendars'

const LIST_KEY = ['work-calendars']
const HOLIDAY_CALENDAR_SOURCES_KEY = ['holiday-calendar-sources']
const YEARS_KEY_PREFIX = ['work-calendar-years']
const yearsKey = (companyCalendarId: string) => [...YEARS_KEY_PREFIX, companyCalendarId]
const listPageKey = (page: number, perPage: number) => [...LIST_KEY, 'page', page, perPage]
const DAYS_KEY_PREFIX = ['work-calendar-year-days']
const daysKey = (companyCalendarYearId: string) => [...DAYS_KEY_PREFIX, companyCalendarYearId]

export function useWorkCalendars() {
  return useQuery({ queryKey: LIST_KEY, queryFn: fetchWorkCalendars })
}

/**
 * WorkCalendarListPage専用のページネーション付き一覧取得。既存の`useWorkCalendars`
 * (配列を返す非ページネーション版)は他画面(WorkCalendarYearsPage・
 * WorkCalendarDetailPage等)がそのまま使い続けるため、こちらは別のquery keyで管理する。
 */
export function useWorkCalendarsPage(page: number, perPage = 20) {
  return useQuery({
    queryKey: listPageKey(page, perPage),
    queryFn: () => fetchWorkCalendarsPage({ page, per_page: perPage }),
  })
}

export function useDeleteWorkCalendar() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => deleteWorkCalendar(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: LIST_KEY })
    },
  })
}

export function useCreateWorkCalendar() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: CreateWorkCalendarInput) => createWorkCalendar(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: LIST_KEY })
    },
  })
}

export function useUpdateWorkCalendar() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, input }: { id: string; input: UpdateWorkCalendarInput }) => updateWorkCalendar(id, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: LIST_KEY })
    },
  })
}

export function useSetDefaultWorkCalendar() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => setDefaultWorkCalendar(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: LIST_KEY })
    },
  })
}

export function useWorkCalendarYears(companyCalendarId: string) {
  return useQuery({
    queryKey: yearsKey(companyCalendarId),
    queryFn: () => fetchWorkCalendarYears(companyCalendarId),
    enabled: Boolean(companyCalendarId),
  })
}

export function useCreateWorkCalendarYear(companyCalendarId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: CreateWorkCalendarYearInput) => createWorkCalendarYear(companyCalendarId, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: yearsKey(companyCalendarId) })
    },
  })
}

export function usePublishWorkCalendarYear(companyCalendarId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => publishWorkCalendarYear(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: yearsKey(companyCalendarId) })
    },
  })
}

export function useUnpublishWorkCalendarYear(companyCalendarId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => unpublishWorkCalendarYear(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: yearsKey(companyCalendarId) })
    },
  })
}

export function useArchiveWorkCalendarYear(companyCalendarId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => archiveWorkCalendarYear(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: yearsKey(companyCalendarId) })
    },
  })
}

export function useDuplicateWorkCalendarYear(companyCalendarId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => duplicateWorkCalendarYear(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: yearsKey(companyCalendarId) })
    },
  })
}

/**
 * UC-C012: カレンダー年度1件分の期間だけを祝日iCalendarソースと同期する。
 * 同期結果は祝日iCalendarソース(`holiday-calendar-sources`)側に反映されるため、
 * そちらのキャッシュを無効化する(ソース管理カード側の表示も最新化される)。
 */
export function useSyncCompanyCalendarYearHolidayCalendar() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (yearId: string) => syncCompanyCalendarYearHolidayCalendar(yearId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: HOLIDAY_CALENDAR_SOURCES_KEY })
    },
  })
}

export function usePutWorkCalendarDays() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, days }: { id: string; days: PutCalendarDayInput[] }) => putWorkCalendarDays(id, days),
    onSuccess: (_data, variables) => {
      void queryClient.invalidateQueries({ queryKey: LIST_KEY })
      void queryClient.invalidateQueries({ queryKey: daysKey(variables.id) })
    },
  })
}

/**
 * UC-C010: カレンダー年度の日別属性(勤務区分・祝日等)の既存登録内容を取得する
 * (`WorkCalendarDaysPage`の日別カレンダーグリッドが編集開始時に読み込む)。
 */
export function useCompanyCalendarYearDays(companyCalendarYearId: string) {
  return useQuery({
    queryKey: daysKey(companyCalendarYearId),
    queryFn: () => fetchCompanyCalendarYearDays(companyCalendarYearId),
    enabled: Boolean(companyCalendarYearId),
  })
}

/**
 * `WorkCalendarDaysPage`はルート上`yearId`のみを持ち、親のカレンダー本体IDを知らない
 * (`WorkCalendarDetailPage`の年度一覧は年度リンクにyearIdのみを埋め込んでいるため)。
 * 単体の年度取得APIが無いため、全カレンダー本体の年度一覧を並行取得して`yearId`で
 * 探し当てる(各カレンダー本体の年度一覧取得は`useWorkCalendarYears`と同じquery keyを
 * 共有するため、既にキャッシュ済みなら再取得は発生しない)。
 */
export function useCompanyCalendarYearById(yearId: string) {
  const calendarsQuery = useWorkCalendars()
  const calendars = calendarsQuery.data ?? []

  const yearQueries = useQueries({
    queries: calendars.map((calendar) => ({
      queryKey: yearsKey(calendar.id),
      queryFn: () => fetchWorkCalendarYears(calendar.id),
      enabled: Boolean(calendarsQuery.data),
    })),
  })

  const isLoading = calendarsQuery.isLoading || (calendars.length > 0 && yearQueries.some((q) => q.isLoading))
  const error = calendarsQuery.error ?? yearQueries.find((q) => q.error)?.error ?? null
  const year = yearQueries.flatMap((q) => q.data ?? []).find((y) => y.id === yearId) ?? null
  const calendar = year ? calendars.find((c) => c.id === year.company_calendar_id) ?? null : null

  return { year, calendar, isLoading, error }
}
