import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  adjustAttendanceDailyCalculation,
  approveMonth,
  clockIn,
  clockOut,
  closeMonth,
  correctPunch,
  createAttendanceDay,
  createPunch,
  deleteAttendanceDay,
  deletePunch,
  endBreak,
  fetchAttendanceDayDefaults,
  fetchMonth,
  fetchMonthsToApprove,
  fetchMyMonths,
  fetchPunches,
  fetchToday,
  fetchWeek,
  returnMonth,
  startBreak,
  submitMonth,
  updateAttendanceDay,
  type CorrectAttendancePunchInput,
  type CreateAttendanceDayInput,
  type CreateAttendancePunchInput,
  type DeleteAttendanceDayInput,
  type EditAttendanceDayInput,
} from '../api/attendance'
import { downloadAttendanceCsv } from '../api/exports'
import type { AttendanceDailyCalculationAdjustment, AttendanceExportFilters } from '../api/types'

const TODAY_KEY = ['attendance', 'today']
const WEEK_KEY = ['attendance', 'week']

export function useTodayAttendance() {
  return useQuery({ queryKey: TODAY_KEY, queryFn: fetchToday })
}

export function useWeek(startDate: string) {
  return useQuery({ queryKey: [...WEEK_KEY, startDate], queryFn: () => fetchWeek(startDate) })
}

/** 日次勤怠の入力画面(未入力の日)を開いた際の初期値。userId/workDateが揃うまでは取得しない。 */
export function useAttendanceDayDefaults(userId: number | undefined, workDate: string | undefined) {
  return useQuery({
    queryKey: ['attendance', 'day-defaults', userId, workDate],
    queryFn: () => fetchAttendanceDayDefaults(userId as number, workDate as string),
    enabled: Boolean(userId && workDate),
  })
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
      void queryClient.invalidateQueries({ queryKey: WEEK_KEY })
    },
  })
}

export function useAdjustAttendanceDailyCalculation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: AttendanceDailyCalculationAdjustment }) =>
      adjustAttendanceDailyCalculation(id, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: TODAY_KEY })
      void queryClient.invalidateQueries({ queryKey: ['attendance', 'month'] })
      void queryClient.invalidateQueries({ queryKey: WEEK_KEY })
    },
  })
}

export function useCreateAttendanceDay() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: CreateAttendanceDayInput) => createAttendanceDay(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: TODAY_KEY })
      void queryClient.invalidateQueries({ queryKey: ['attendance', 'month'] })
      void queryClient.invalidateQueries({ queryKey: WEEK_KEY })
    },
  })
}

export function useDeleteAttendanceDay() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: DeleteAttendanceDayInput }) => deleteAttendanceDay(id, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: PUNCHES_KEY })
      void queryClient.invalidateQueries({ queryKey: TODAY_KEY })
      void queryClient.invalidateQueries({ queryKey: ['attendance', 'month'] })
      void queryClient.invalidateQueries({ queryKey: WEEK_KEY })
    },
  })
}

const PUNCHES_KEY = ['attendance', 'punches']

export function usePunches(params: { from?: string; to?: string }) {
  return useQuery({
    queryKey: [...PUNCHES_KEY, params.from, params.to],
    queryFn: () => fetchPunches(params),
    enabled: Boolean(params.from && params.to),
  })
}

function usePunchAction<TInput>(action: (id: number, input: TInput) => Promise<unknown>) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: TInput }) => action(id, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: PUNCHES_KEY })
      void queryClient.invalidateQueries({ queryKey: TODAY_KEY })
      void queryClient.invalidateQueries({ queryKey: ['attendance', 'month'] })
      void queryClient.invalidateQueries({ queryKey: WEEK_KEY })
    },
  })
}

export function useCreatePunch() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: CreateAttendancePunchInput) => createPunch(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: PUNCHES_KEY })
      void queryClient.invalidateQueries({ queryKey: TODAY_KEY })
      void queryClient.invalidateQueries({ queryKey: ['attendance', 'month'] })
      void queryClient.invalidateQueries({ queryKey: WEEK_KEY })
    },
  })
}

export function useCorrectPunch() {
  return usePunchAction<CorrectAttendancePunchInput>(correctPunch)
}

export function useDeletePunch() {
  return usePunchAction<string>((id, reason) => deletePunch(id, reason))
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

/**
 * UC-E001: 勤怠CSVのダウンロード。キャッシュするJSONを返すわけではなく
 * ブラウザのダウンロードを発生させる副作用のため、useQueryではなくuseMutationを使う。
 */
export function useDownloadAttendanceCsv() {
  return useMutation({
    mutationFn: (filters: AttendanceExportFilters) => downloadAttendanceCsv(filters),
  })
}
