import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  createWorkCalendar,
  fetchWorkCalendars,
  publishWorkCalendar,
  putWorkCalendarDays,
  type CreateWorkCalendarInput,
  type PutCalendarDayInput,
} from '../api/workCalendars'

const LIST_KEY = ['work-calendars']

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

export function usePublishWorkCalendar() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => publishWorkCalendar(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: LIST_KEY })
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
