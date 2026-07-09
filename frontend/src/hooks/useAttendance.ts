import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  clockIn,
  clockOut,
  endBreak,
  fetchMonth,
  fetchToday,
  startBreak,
  submitMonth,
  updateAttendanceDay,
  type EditAttendanceDayInput,
} from '../api/attendance'

const TODAY_KEY = ['attendance', 'today']

export function useTodayAttendance() {
  return useQuery({ queryKey: TODAY_KEY, queryFn: fetchToday })
}

function useAttendanceAction(action: () => Promise<unknown>) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: action,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: TODAY_KEY })
    },
  })
}

export function useClockIn() {
  return useAttendanceAction(clockIn)
}

export function useStartBreak() {
  return useAttendanceAction(startBreak)
}

export function useEndBreak() {
  return useAttendanceAction(endBreak)
}

export function useClockOut() {
  return useAttendanceAction(clockOut)
}

export function useUpdateAttendanceDay() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: EditAttendanceDayInput }) =>
      updateAttendanceDay(id, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: TODAY_KEY })
      void queryClient.invalidateQueries({ queryKey: ['attendance', 'month'] })
    },
  })
}

export function useAttendanceMonth(yearMonth: string) {
  return useQuery({
    queryKey: ['attendance', 'month', yearMonth],
    queryFn: () => fetchMonth(yearMonth),
  })
}

export function useSubmitMonth(yearMonth: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (approverUserId: number) => submitMonth(yearMonth, approverUserId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['attendance', 'month', yearMonth] })
    },
  })
}
