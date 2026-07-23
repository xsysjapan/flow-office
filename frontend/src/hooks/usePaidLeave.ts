import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  approvePaidLeaveRequest,
  cancelPaidLeaveRequest,
  createPaidLeaveGrantRule,
  createPaidLeaveRequest,
  fetchMyPaidLeaveGrants,
  fetchMyPaidLeaveHistory,
  fetchMyPaidLeaveRequests,
  fetchPaidLeaveGrantRules,
  fetchPaidLeaveGrantsForUser,
  fetchPaidLeaveHistoryForUser,
  fetchPaidLeaveRequestsToApprove,
  grantPaidLeave,
  returnPaidLeaveRequest,
  type CreatePaidLeaveGrantRuleInput,
  type CreatePaidLeaveRequestInput,
  type GrantPaidLeaveInput,
} from '../api/paidLeave'

const RULES_KEY = ['paid-leave', 'grant-rules']
const MY_GRANTS_KEY = ['paid-leave', 'grants', 'mine']
const MY_REQUESTS_KEY = ['paid-leave', 'requests', 'mine']
const REQUESTS_TO_APPROVE_KEY = ['paid-leave', 'requests', 'to-approve']

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

export function useMyPaidLeaveRequests() {
  return useQuery({ queryKey: MY_REQUESTS_KEY, queryFn: fetchMyPaidLeaveRequests })
}

export function usePaidLeaveRequestsToApprove() {
  return useQuery({ queryKey: REQUESTS_TO_APPROVE_KEY, queryFn: fetchPaidLeaveRequestsToApprove })
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
    void queryClient.invalidateQueries({ queryKey: REQUESTS_TO_APPROVE_KEY })
    void queryClient.invalidateQueries({ queryKey: MY_GRANTS_KEY })
  }
}

export function useApprovePaidLeaveRequest() {
  const invalidate = useInvalidatePaidLeaveRequests()

  return useMutation({
    mutationFn: (id: string) => approvePaidLeaveRequest(id),
    onSuccess: () => invalidate(),
  })
}

export function useReturnPaidLeaveRequest() {
  const invalidate = useInvalidatePaidLeaveRequests()

  return useMutation({
    mutationFn: ({ id, comment }: { id: string; comment: string }) => returnPaidLeaveRequest(id, comment),
    onSuccess: () => invalidate(),
  })
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
