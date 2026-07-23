import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  approveSpecialLeaveRequest,
  cancelSpecialLeaveRequest,
  createSpecialLeaveGrantRule,
  createSpecialLeaveRequest,
  createSpecialLeaveType,
  fetchMySpecialLeaveGrants,
  fetchMySpecialLeaveHistory,
  fetchMySpecialLeaveRequests,
  fetchSpecialLeaveGrantRules,
  fetchSpecialLeaveGrantsForUser,
  fetchSpecialLeaveHistoryForUser,
  fetchSpecialLeaveRequestsToApprove,
  fetchSpecialLeaveTypes,
  grantSpecialLeave,
  returnSpecialLeaveRequest,
  updateSpecialLeaveType,
  type CreateSpecialLeaveGrantRuleInput,
  type CreateSpecialLeaveRequestInput,
  type CreateSpecialLeaveTypeInput,
  type GrantSpecialLeaveInput,
  type UpdateSpecialLeaveTypeInput,
} from '../api/specialLeave'

const TYPES_KEY = ['special-leave', 'types']
const RULES_KEY = ['special-leave', 'grant-rules']
const MY_GRANTS_KEY = ['special-leave', 'grants', 'mine']
const MY_REQUESTS_KEY = ['special-leave', 'requests', 'mine']
const REQUESTS_TO_APPROVE_KEY = ['special-leave', 'requests', 'to-approve']

/** 特別休暇メニューの表示可否(有効な種別が1件以上あるか)の判定にも使う。 */
export function useSpecialLeaveTypes() {
  return useQuery({ queryKey: TYPES_KEY, queryFn: fetchSpecialLeaveTypes })
}

export function useCreateSpecialLeaveType() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: CreateSpecialLeaveTypeInput) => createSpecialLeaveType(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: TYPES_KEY })
    },
  })
}

export function useUpdateSpecialLeaveType() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: UpdateSpecialLeaveTypeInput }) => updateSpecialLeaveType(id, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: TYPES_KEY })
    },
  })
}

export function useMySpecialLeaveGrants() {
  return useQuery({ queryKey: MY_GRANTS_KEY, queryFn: fetchMySpecialLeaveGrants })
}

export function useSpecialLeaveGrantsForUser(userId: number) {
  return useQuery({
    queryKey: ['special-leave', 'grants', 'user', userId],
    queryFn: () => fetchSpecialLeaveGrantsForUser(userId),
    enabled: Number.isFinite(userId),
  })
}

export function useSpecialLeaveGrantRules() {
  return useQuery({ queryKey: RULES_KEY, queryFn: fetchSpecialLeaveGrantRules })
}

export function useCreateSpecialLeaveGrantRule() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: CreateSpecialLeaveGrantRuleInput) => createSpecialLeaveGrantRule(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: RULES_KEY })
    },
  })
}

export function useGrantSpecialLeave() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: GrantSpecialLeaveInput) => grantSpecialLeave(input),
    onSuccess: (_data, input) => {
      void queryClient.invalidateQueries({ queryKey: ['special-leave', 'grants', 'user', input.user_id] })
    },
  })
}

export function useMySpecialLeaveRequests() {
  return useQuery({ queryKey: MY_REQUESTS_KEY, queryFn: fetchMySpecialLeaveRequests })
}

export function useSpecialLeaveRequestsToApprove() {
  return useQuery({ queryKey: REQUESTS_TO_APPROVE_KEY, queryFn: fetchSpecialLeaveRequestsToApprove })
}

export function useCreateSpecialLeaveRequest() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: CreateSpecialLeaveRequestInput) => createSpecialLeaveRequest(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: MY_REQUESTS_KEY })
    },
  })
}

function useInvalidateSpecialLeaveRequests() {
  const queryClient = useQueryClient()

  return () => {
    void queryClient.invalidateQueries({ queryKey: MY_REQUESTS_KEY })
    void queryClient.invalidateQueries({ queryKey: REQUESTS_TO_APPROVE_KEY })
    void queryClient.invalidateQueries({ queryKey: MY_GRANTS_KEY })
  }
}

export function useApproveSpecialLeaveRequest() {
  const invalidate = useInvalidateSpecialLeaveRequests()

  return useMutation({
    mutationFn: (id: string) => approveSpecialLeaveRequest(id),
    onSuccess: () => invalidate(),
  })
}

export function useReturnSpecialLeaveRequest() {
  const invalidate = useInvalidateSpecialLeaveRequests()

  return useMutation({
    mutationFn: ({ id, comment }: { id: string; comment: string }) => returnSpecialLeaveRequest(id, comment),
    onSuccess: () => invalidate(),
  })
}

export function useCancelSpecialLeaveRequest() {
  const invalidate = useInvalidateSpecialLeaveRequests()

  return useMutation({
    mutationFn: (id: string) => cancelSpecialLeaveRequest(id),
    onSuccess: () => invalidate(),
  })
}

export function useMySpecialLeaveHistory() {
  return useQuery({ queryKey: ['special-leave', 'history', 'mine'], queryFn: fetchMySpecialLeaveHistory })
}

export function useSpecialLeaveHistoryForUser(userId: number) {
  return useQuery({
    queryKey: ['special-leave', 'history', 'user', userId],
    queryFn: () => fetchSpecialLeaveHistoryForUser(userId),
    enabled: Number.isFinite(userId),
  })
}
