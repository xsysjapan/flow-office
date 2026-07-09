import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  approveMonth,
  clockIn,
  clockOut,
  closeMonth,
  endBreak,
  fetchMonth,
  fetchMonthsToApprove,
  fetchMyMonths,
  fetchToday,
  returnMonth,
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
      void queryClient.invalidateQueries({ queryKey: ['attendance', 'months', 'mine'] })
    },
  })
}

const MY_MONTHS_KEY = ['attendance', 'months', 'mine']
const MONTHS_TO_APPROVE_KEY = ['attendance', 'months', 'to-approve']

export function useMyMonths() {
  return useQuery({ queryKey: MY_MONTHS_KEY, queryFn: fetchMyMonths })
}

export function useMonthsToApprove() {
  return useQuery({ queryKey: MONTHS_TO_APPROVE_KEY, queryFn: fetchMonthsToApprove })
}

function useInvalidateMonths() {
  const queryClient = useQueryClient()

  return () => {
    void queryClient.invalidateQueries({ queryKey: MY_MONTHS_KEY })
    void queryClient.invalidateQueries({ queryKey: MONTHS_TO_APPROVE_KEY })
  }
}

export function useApproveMonth() {
  const invalidate = useInvalidateMonths()

  return useMutation({
    mutationFn: (id: number) => approveMonth(id),
    onSuccess: () => invalidate(),
  })
}

export function useReturnMonth() {
  const invalidate = useInvalidateMonths()

  return useMutation({
    mutationFn: ({ id, comment }: { id: number; comment: string }) => returnMonth(id, comment),
    onSuccess: () => invalidate(),
  })
}

export function useCloseMonth() {
  const invalidate = useInvalidateMonths()

  return useMutation({
    mutationFn: (id: number) => closeMonth(id),
    onSuccess: () => invalidate(),
  })
}
