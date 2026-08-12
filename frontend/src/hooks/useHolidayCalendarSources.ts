import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  createHolidayCalendarSource,
  disableHolidayCalendarSource,
  fetchHolidayCalendarSources,
  revertLastHolidayCalendarSync,
  syncHolidayCalendarSource,
  type CreateHolidayCalendarSourceInput,
} from '../api/holidayCalendarSources'

const LIST_KEY = ['holiday-calendar-sources']

export function useHolidayCalendarSources() {
  return useQuery({ queryKey: LIST_KEY, queryFn: fetchHolidayCalendarSources })
}

export function useCreateHolidayCalendarSource() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: CreateHolidayCalendarSourceInput) => createHolidayCalendarSource(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: LIST_KEY })
    },
  })
}

export function useSyncHolidayCalendarSource() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => syncHolidayCalendarSource(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: LIST_KEY })
    },
  })
}

export function useDisableHolidayCalendarSource() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => disableHolidayCalendarSource(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: LIST_KEY })
    },
  })
}

export function useRevertLastHolidayCalendarSync() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => revertLastHolidayCalendarSync(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: LIST_KEY })
    },
  })
}
