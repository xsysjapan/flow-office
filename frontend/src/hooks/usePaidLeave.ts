import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  adminCancelPaidLeaveRequest,
  cancelPaidLeaveRequest,
  createPaidLeaveGrantRule,
  createPaidLeaveRequest,
  fetchMyPaidLeaveGrants,
  fetchMyPaidLeaveHistory,
  fetchMyPaidLeaveRequests,
  fetchPaidLeaveGrantRules,
  fetchPaidLeaveGrantRuleTargetUsers,
  fetchPaidLeaveGrantsForUser,
  fetchPaidLeaveHistoryForUser,
  fetchPaidLeaveUsagesForUser,
  grantPaidLeave,
  revokePaidLeaveGrant,
  type CreatePaidLeaveGrantRuleInput,
  type CreatePaidLeaveRequestInput,
  type GrantPaidLeaveInput,
} from '../api/paidLeave'

const RULES_KEY = ['paid-leave', 'grant-rules']
const MY_GRANTS_KEY = ['paid-leave', 'grants', 'mine']
const MY_REQUESTS_KEY = ['paid-leave', 'requests', 'mine']

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
