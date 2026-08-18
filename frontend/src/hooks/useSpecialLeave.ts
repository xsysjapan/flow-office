import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  adminCancelSpecialLeaveRequest,
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
  fetchSpecialLeaveTypes,
  fetchSpecialLeaveUsagesForUser,
  grantSpecialLeave,
  revokeSpecialLeaveGrant,
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

/** 特別休暇メニューの表示可否(有効な種別が1件以上あるか)の判定にも使う。 */
export function useSpecialLeaveTypes(enabled = true) {
  return useQuery({ queryKey: TYPES_KEY, queryFn: fetchSpecialLeaveTypes, enabled })
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

export function useSpecialLeaveGrantsForUser(userId: string) {
  return useQuery({
    queryKey: ['special-leave', 'grants', 'user', userId],
    queryFn: () => fetchSpecialLeaveGrantsForUser(userId),
    enabled: Boolean(userId),
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

export function useRevokeSpecialLeaveGrant() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ grantId, reason }: { grantId: string; reason?: string }) => revokeSpecialLeaveGrant(grantId, reason),
    onSuccess: (data) => {
      void queryClient.invalidateQueries({ queryKey: ['special-leave', 'grants', 'user', data.user_id] })
    },
  })
}

export function useMySpecialLeaveRequests() {
  return useQuery({ queryKey: MY_REQUESTS_KEY, queryFn: fetchMySpecialLeaveRequests })
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
    void queryClient.invalidateQueries({ queryKey: MY_GRANTS_KEY })
  }
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

export function useSpecialLeaveHistoryForUser(userId: string) {
  return useQuery({
    queryKey: ['special-leave', 'history', 'user', userId],
    queryFn: () => fetchSpecialLeaveHistoryForUser(userId),
    enabled: Boolean(userId),
  })
}

function specialLeaveUsagesForUserKey(userId: string) {
  return ['special-leave', 'usages', 'user', userId]
}

export function useSpecialLeaveUsagesForUser(userId: string) {
  return useQuery({
    queryKey: specialLeaveUsagesForUserKey(userId),
    queryFn: () => fetchSpecialLeaveUsagesForUser(userId),
    enabled: Boolean(userId),
  })
}

/** 管理者による特別休暇申請の取消。取消対象社員のusages一覧を再取得する。 */
export function useAdminCancelSpecialLeaveRequest(userId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (requestId: string) => adminCancelSpecialLeaveRequest(requestId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: specialLeaveUsagesForUserKey(userId) })
    },
  })
}
