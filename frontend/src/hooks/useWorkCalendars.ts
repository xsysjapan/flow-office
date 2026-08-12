import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  archiveWorkCalendarYear,
  createWorkCalendar,
  createWorkCalendarYear,
  duplicateWorkCalendarYear,
  fetchWorkCalendars,
  fetchWorkCalendarYears,
  generateCompanyCalendarYearsNow,
  publishWorkCalendarYear,
  putWorkCalendarDays,
  setDefaultWorkCalendar,
  unpublishWorkCalendarYear,
  type CreateWorkCalendarInput,
  type CreateWorkCalendarYearInput,
  type PutCalendarDayInput,
} from '../api/workCalendars'

const LIST_KEY = ['work-calendars']
const YEARS_KEY_PREFIX = ['work-calendar-years']
const yearsKey = (companyCalendarId: string) => [...YEARS_KEY_PREFIX, companyCalendarId]

export function useWorkCalendars() {
  return useQuery({ queryKey: LIST_KEY, queryFn: fetchWorkCalendars })
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

/** UC-C011「今すぐ生成する」。生成後は本体一覧・年度一覧の両方が古くなりうるため無効化する。 */
export function useGenerateCompanyCalendarYearsNow() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => generateCompanyCalendarYearsNow(),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: LIST_KEY })
      void queryClient.invalidateQueries({ queryKey: YEARS_KEY_PREFIX })
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
