import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  archiveWorkCalendarYear,
  createWorkCalendar,
  createWorkCalendarYear,
  duplicateWorkCalendarYear,
  fetchWorkCalendars,
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
