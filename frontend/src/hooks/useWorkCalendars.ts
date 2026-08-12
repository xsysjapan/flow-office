import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  archiveWorkCalendarYear,
  createWorkCalendar,
  createWorkCalendarYear,
  deleteWorkCalendar,
  duplicateWorkCalendarYear,
  fetchWorkCalendars,
  fetchWorkCalendarsPage,
  fetchWorkCalendarYears,
  publishWorkCalendarYear,
  putWorkCalendarDays,
  setDefaultWorkCalendar,
  unpublishWorkCalendarYear,
  updateWorkCalendar,
  type CreateWorkCalendarInput,
  type CreateWorkCalendarYearInput,
  type PutCalendarDayInput,
  type UpdateWorkCalendarInput,
} from '../api/workCalendars'

const LIST_KEY = ['work-calendars']
const YEARS_KEY_PREFIX = ['work-calendar-years']
const yearsKey = (companyCalendarId: string) => [...YEARS_KEY_PREFIX, companyCalendarId]
const listPageKey = (page: number, perPage: number) => [...LIST_KEY, 'page', page, perPage]

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

export function usePutWorkCalendarDays() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, days }: { id: string; days: PutCalendarDayInput[] }) => putWorkCalendarDays(id, days),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: LIST_KEY })
    },
  })
}
