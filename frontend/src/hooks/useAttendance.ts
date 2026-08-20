import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  adjustAttendanceDailyCalculation,
  allocateWeekOvertime,
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
  fetchAttendanceMonthById,
  fetchMonth,
  fetchMonthsForUser,
  fetchMonthsToApprove,
  fetchMyMonths,
  fetchPunches,
  fetchToday,
  fetchWeek,
  fetchWeekOvertime,
  generateAttendancePattern,
  previewAttendancePattern,
  startBreak,
  submitMonth,
  updateAttendanceDay,
  type CorrectAttendancePunchInput,
  type CreateAttendanceDayInput,
  type CreateAttendancePunchInput,
  type DeleteAttendanceDayInput,
  type EditAttendanceDayInput,
  type FetchMonthsToApproveOptions,
  type GenerateAttendancePatternInput,
  type PreviewAttendancePatternInput,
} from '../api/attendance'
import { downloadAttendanceCsv, downloadAttendanceExcel } from '../api/exports'
import type { AttendanceDailyCalculationAdjustment, AttendanceExportFilters } from '../api/types'

const TODAY_KEY = ['attendance', 'today']
const WEEK_KEY = ['attendance', 'week']

export function useTodayAttendance() {
  return useQuery({ queryKey: TODAY_KEY, queryFn: fetchToday })
}

/** userIdを指定すると自分以外の社員の週次勤怠を参照できる(adminのみ)。既存のキャッシュキーと
 *  互換性を保つため、userId未指定時は末尾に付与しない。 */
export function useWeek(startDate: string, userId?: string) {
  return useQuery({
    queryKey: userId === undefined ? [...WEEK_KEY, startDate] : [...WEEK_KEY, startDate, userId],
    queryFn: () => (userId === undefined ? fetchWeek(startDate) : fetchWeek(startDate, userId)),
  })
}

export function useWeekOvertime(startDate: string, userId?: string) {
  return useQuery({
    queryKey: userId === undefined ? [...WEEK_KEY, startDate, 'overtime'] : [...WEEK_KEY, startDate, userId, 'overtime'],
    queryFn: () => fetchWeekOvertime(startDate, userId),
  })
}

export function useAllocateWeekOvertime(startDate: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (allocations: Parameters<typeof allocateWeekOvertime>[1]) => allocateWeekOvertime(startDate, allocations),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [...WEEK_KEY, startDate] }),
  })
}

/** 日次勤怠の入力画面(未入力の日)を開いた際の初期値。userId/workDateが揃うまでは取得しない。 */
export function useAttendanceDayDefaults(userId: string | undefined, workDate: string | undefined) {
  return useQuery({
    queryKey: ['attendance', 'day-defaults', userId, workDate],
    queryFn: () => fetchAttendanceDayDefaults(userId as string, workDate as string),
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
    mutationFn: ({ id, input }: { id: string; input: EditAttendanceDayInput }) =>
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
    mutationFn: ({ id, input }: { id: string; input: AttendanceDailyCalculationAdjustment }) =>
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

/** 週次・月次一括入力: 確定前に実績の展開結果をプレビューする(永続化しない)。 */
export function usePreviewAttendancePattern() {
  return useMutation({
    mutationFn: (input: PreviewAttendancePatternInput) => previewAttendancePattern(input),
  })
}

/** 週次・月次一括入力: パターンを確定し、実績(attendance_days)を一括作成・更新する。 */
export function useGenerateAttendancePattern() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: GenerateAttendancePatternInput) => generateAttendancePattern(input),
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
    mutationFn: ({ id, input }: { id: string; input: DeleteAttendanceDayInput }) => deleteAttendanceDay(id, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: PUNCHES_KEY })
      void queryClient.invalidateQueries({ queryKey: TODAY_KEY })
      void queryClient.invalidateQueries({ queryKey: ['attendance', 'month'] })
      void queryClient.invalidateQueries({ queryKey: WEEK_KEY })
    },
  })
}

const PUNCHES_KEY = ['attendance', 'punches']

export function usePunches(params: { from?: string; to?: string; userId?: string }) {
  return useQuery({
    queryKey: [...PUNCHES_KEY, params.from, params.to, params.userId],
    queryFn: () => fetchPunches(params),
    enabled: Boolean(params.from && params.to),
  })
}

function usePunchAction<TInput>(action: (id: string, input: TInput) => Promise<unknown>) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, input }: { id: string; input: TInput }) => action(id, input),
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

/** userIdを指定すると自分以外の社員の月次勤怠を参照できる(adminのみ)。既存のキャッシュキーと
 *  互換性を保つため、userId未指定時は末尾に付与しない。 */
export function useAttendanceMonth(yearMonth: string, userId?: string) {
  return useQuery({
    queryKey: userId === undefined ? ['attendance', 'month', yearMonth] : ['attendance', 'month', yearMonth, userId],
    queryFn: () => (userId === undefined ? fetchMonth(yearMonth) : fetchMonth(yearMonth, userId)),
  })
}

export function useSubmitMonth(yearMonth: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (approverUserId?: string) => submitMonth(yearMonth, approverUserId),
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

export function useMonthsToApprove(options: FetchMonthsToApproveOptions = {}) {
  return useQuery({
    queryKey: [...MONTHS_TO_APPROVE_KEY, options.status ?? '', options.yearMonth ?? '', options.userId ?? '', options.page ?? 1],
    queryFn: () => fetchMonthsToApprove(options),
    placeholderData: keepPreviousData,
  })
}

/** 管理者が対象社員を選んで月次勤怠一覧(月の選択画面)を確認する。userId未確定の間は取得しない。 */
export function useMonthsForUser(userId: string | undefined) {
  return useQuery({
    queryKey: ['attendance', 'months', 'user', userId],
    queryFn: () => fetchMonthsForUser(userId as string),
    enabled: userId !== undefined,
  })
}

function useInvalidateMonths() {
  const queryClient = useQueryClient()

  return () => {
    void queryClient.invalidateQueries({ queryKey: MY_MONTHS_KEY })
    void queryClient.invalidateQueries({ queryKey: MONTHS_TO_APPROVE_KEY })
    void queryClient.invalidateQueries({ queryKey: ['attendance', 'month', 'by-id'] })
  }
}

/** バックオフィスタスク詳細から、idで単一の月次勤怠(締め状態)を取得する。 */
export function useAttendanceMonthById(id: string) {
  return useQuery({
    queryKey: ['attendance', 'month', 'by-id', id],
    queryFn: () => fetchAttendanceMonthById(id),
  })
}

export function useCloseMonth() {
  const invalidate = useInvalidateMonths()

  return useMutation({
    mutationFn: (id: string) => closeMonth(id),
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

/**
 * UC-E001: 勤怠Excel(単一社員・単一月次なら.xlsx、複数対象ならZIP)のダウンロード。
 * downloadAttendanceCsvと同じ理由でuseMutationを使う。
 */
export function useDownloadAttendanceExcel() {
  return useMutation({
    mutationFn: (filters: Pick<AttendanceExportFilters, 'year_month' | 'user_id'>) =>
      downloadAttendanceExcel(filters),
  })
}
