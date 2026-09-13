import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  adminCancelPaidLeaveRequest,
  bulkGrantPaidLeaveScheduleEntries,
  cancelPaidLeaveRequest,
  createPaidLeaveGrantRule,
  createPaidLeaveRequest,
  deletePaidLeaveGrantRule,
  fetchMyPaidLeaveGrants,
  fetchMyPaidLeaveHistory,
  fetchMyPaidLeaveRequests,
  fetchPaidLeaveGrantPolicies,
  fetchPaidLeaveGrantRules,
  fetchPaidLeaveGrantRuleTargetUsers,
  fetchPaidLeaveGrantsForUser,
  fetchPaidLeaveHistoryForUser,
  fetchPaidLeaveScheduleEntries,
  fetchPaidLeaveScheduleEntry,
  fetchPaidLeaveUsagesForUser,
  grantPaidLeave,
  overridePaidLeaveScheduleAssessment,
  reassessPaidLeaveScheduleEntry,
  revokePaidLeaveGrant,
  updatePaidLeaveGrantRule,
  type CreatePaidLeaveGrantRuleInput,
  type CreatePaidLeaveRequestInput,
  type GrantPaidLeaveInput,
  type OverridePaidLeaveScheduleAssessmentInput,
  type PaidLeaveScheduleFilter,
} from '../api/paidLeave'

const RULES_KEY = ['paid-leave', 'grant-rules']
const GRANT_POLICIES_KEY = ['paid-leave', 'grant-policies']
const MY_GRANTS_KEY = ['paid-leave', 'grants', 'mine']
const MY_REQUESTS_KEY = ['paid-leave', 'requests', 'mine']

function scheduleEntriesKey(filter: PaidLeaveScheduleFilter) {
  return ['paid-leave', 'schedule-entries', filter]
}

function scheduleEntryKey(scheduleEntryId: string) {
  return ['paid-leave', 'schedule-entries', 'detail', scheduleEntryId]
}

export function useMyPaidLeaveGrants() {
  return useQuery({ queryKey: MY_GRANTS_KEY, queryFn: fetchMyPaidLeaveGrants })
}

export function usePaidLeaveGrantsForUser(userId: string) {
  return useQuery({
    queryKey: ['paid-leave', 'grants', 'user', userId],
    queryFn: () => fetchPaidLeaveGrantsForUser(userId),
    enabled: Boolean(userId),
  })
}

export function usePaidLeaveGrantRules() {
  return useQuery({ queryKey: RULES_KEY, queryFn: fetchPaidLeaveGrantRules })
}

/** 付与ルールの対象社員一覧(対象社員セクション用)。展開時にのみ取得すればよいので `enabled` で制御する。 */
export function usePaidLeaveGrantRuleTargetUsers(ruleId: number, enabled = true) {
  return useQuery({
    queryKey: ['paid-leave', 'grant-rules', ruleId, 'target-users'],
    queryFn: () => fetchPaidLeaveGrantRuleTargetUsers(ruleId),
    enabled,
  })
}

export function useCreatePaidLeaveGrantRule() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: CreatePaidLeaveGrantRuleInput) => createPaidLeaveGrantRule(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: RULES_KEY })
    },
  })
}

/** spec.md 論点15-1: ルール内容の編集。ルール一覧・(存在すれば)対象社員一覧を無効化する。 */
export function useUpdatePaidLeaveGrantRule() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: CreatePaidLeaveGrantRuleInput }) => updatePaidLeaveGrantRule(id, input),
    onSuccess: (_data, { id }) => {
      void queryClient.invalidateQueries({ queryKey: RULES_KEY })
      void queryClient.invalidateQueries({ queryKey: ['paid-leave', 'grant-rules', id, 'target-users'] })
    },
  })
}

/** 無効化済みルールの削除。 */
export function useDeletePaidLeaveGrantRule() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => deletePaidLeaveGrantRule(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: RULES_KEY })
    },
  })
}

/** 法定通常付与表・比例付与表(最新version)。滅多に変わらない参照データのためstaleTimeを長めにする。 */
export function usePaidLeaveGrantPolicies() {
  return useQuery({
    queryKey: GRANT_POLICIES_KEY,
    queryFn: fetchPaidLeaveGrantPolicies,
    staleTime: 5 * 60 * 1000,
  })
}

export function useGrantPaidLeave() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: GrantPaidLeaveInput) => grantPaidLeave(input),
    onSuccess: (_data, input) => {
      void queryClient.invalidateQueries({ queryKey: ['paid-leave', 'grants', 'user', input.user_id] })
    },
  })
}

export function useRevokePaidLeaveGrant() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ grantId, reason }: { grantId: string; reason?: string }) => revokePaidLeaveGrant(grantId, reason),
    onSuccess: (data) => {
      void queryClient.invalidateQueries({ queryKey: ['paid-leave', 'grants', 'user', data.user_id] })
    },
  })
}

export function useMyPaidLeaveRequests() {
  return useQuery({ queryKey: MY_REQUESTS_KEY, queryFn: fetchMyPaidLeaveRequests })
}

export function useCreatePaidLeaveRequest() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: CreatePaidLeaveRequestInput) => createPaidLeaveRequest(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: MY_REQUESTS_KEY })
    },
  })
}

function useInvalidatePaidLeaveRequests() {
  const queryClient = useQueryClient()

  return () => {
    void queryClient.invalidateQueries({ queryKey: MY_REQUESTS_KEY })
    void queryClient.invalidateQueries({ queryKey: MY_GRANTS_KEY })
  }
}

export function useCancelPaidLeaveRequest() {
  const invalidate = useInvalidatePaidLeaveRequests()

  return useMutation({
    mutationFn: (id: string) => cancelPaidLeaveRequest(id),
    onSuccess: () => invalidate(),
  })
}

export function useMyPaidLeaveHistory() {
  return useQuery({ queryKey: ['paid-leave', 'history', 'mine'], queryFn: fetchMyPaidLeaveHistory })
}

export function usePaidLeaveHistoryForUser(userId: string) {
  return useQuery({
    queryKey: ['paid-leave', 'history', 'user', userId],
    queryFn: () => fetchPaidLeaveHistoryForUser(userId),
    enabled: Boolean(userId),
  })
}

function paidLeaveUsagesForUserKey(userId: string) {
  return ['paid-leave', 'usages', 'user', userId]
}

export function usePaidLeaveUsagesForUser(userId: string) {
  return useQuery({
    queryKey: paidLeaveUsagesForUserKey(userId),
    queryFn: () => fetchPaidLeaveUsagesForUser(userId),
    enabled: Boolean(userId),
  })
}

/** 管理者による有給申請の取消。取消対象社員のusages一覧を再取得する。 */
export function useAdminCancelPaidLeaveRequest(userId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (requestId: string) => adminCancelPaidLeaveRequest(requestId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: paidLeaveUsagesForUserKey(userId) })
    },
  })
}

/** 付与予定Scheduleエントリ一覧(spec.md論点12・15-5、フィルタ状態はページ側でURLへ同期する)。 */
export function usePaidLeaveScheduleEntries(filter: PaidLeaveScheduleFilter) {
  return useQuery({
    queryKey: scheduleEntriesKey(filter),
    queryFn: () => fetchPaidLeaveScheduleEntries(filter),
  })
}

/** 付与予定Scheduleエントリの詳細(行詳細Sheet用)。 */
export function usePaidLeaveScheduleEntry(scheduleEntryId: string | null) {
  return useQuery({
    queryKey: scheduleEntryKey(scheduleEntryId ?? ''),
    queryFn: () => fetchPaidLeaveScheduleEntry(scheduleEntryId as string),
    enabled: scheduleEntryId !== null,
  })
}

/** 全フィルタタブ一覧+詳細のキャッシュをまとめて無効化する(再判定・上書き・一括付与の共通後処理)。 */
function useInvalidatePaidLeaveScheduleEntries() {
  const queryClient = useQueryClient()

  return (scheduleEntryId?: string) => {
    void queryClient.invalidateQueries({ queryKey: ['paid-leave', 'schedule-entries'] })
    if (scheduleEntryId) {
      void queryClient.invalidateQueries({ queryKey: scheduleEntryKey(scheduleEntryId) })
    }
  }
}

/** 出勤率の再判定(依頼書§40「再判定」導線)。 */
export function useReassessPaidLeaveScheduleEntry() {
  const invalidate = useInvalidatePaidLeaveScheduleEntries()

  return useMutation({
    mutationFn: (scheduleEntryId: string) => reassessPaidLeaveScheduleEntry(scheduleEntryId),
    onSuccess: (data) => invalidate(data.id),
  })
}

/** 判定結果の上書き(理由必須、依頼書§40「判定結果を上書き」導線)。 */
export function useOverridePaidLeaveScheduleAssessment() {
  const invalidate = useInvalidatePaidLeaveScheduleEntries()

  return useMutation({
    mutationFn: ({
      scheduleEntryId,
      input,
    }: {
      scheduleEntryId: string
      input: OverridePaidLeaveScheduleAssessmentInput
    }) => overridePaidLeaveScheduleAssessment(scheduleEntryId, input),
    onSuccess: (data) => invalidate(data.id),
  })
}

/** 選択したEligibleエントリの一括付与(spec.md論点15-6)。 */
export function useBulkGrantPaidLeaveScheduleEntries() {
  const invalidate = useInvalidatePaidLeaveScheduleEntries()

  return useMutation({
    mutationFn: (scheduleEntryIds: string[]) => bulkGrantPaidLeaveScheduleEntries(scheduleEntryIds),
    onSuccess: () => invalidate(),
  })
}
