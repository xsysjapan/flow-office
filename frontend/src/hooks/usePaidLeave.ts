import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  createPaidLeaveGrantRule,
  fetchMyPaidLeaveGrants,
  fetchPaidLeaveGrantRules,
  fetchPaidLeaveGrantsForUser,
  grantPaidLeave,
  type CreatePaidLeaveGrantRuleInput,
  type GrantPaidLeaveInput,
} from '../api/paidLeave'

const RULES_KEY = ['paid-leave', 'grant-rules']

export function useMyPaidLeaveGrants() {
  return useQuery({ queryKey: ['paid-leave', 'grants', 'mine'], queryFn: fetchMyPaidLeaveGrants })
}

export function usePaidLeaveGrantsForUser(userId: number) {
  return useQuery({
    queryKey: ['paid-leave', 'grants', 'user', userId],
    queryFn: () => fetchPaidLeaveGrantsForUser(userId),
    enabled: Number.isFinite(userId),
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
