import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  adminCancelCompensatoryLeaveRequest,
  cancelCompensatoryLeaveRequest,
  createCompensatoryLeaveRequest,
  createCompensatoryLeaveWorkflowRequest,
  fetchCompensatoryLeaveGrantsForUser,
  fetchCompensatoryLeaveHistoryForUser,
  fetchCompensatoryLeaveUsagesForUser,
  fetchMyCompensatoryLeaveGrants,
  fetchMyCompensatoryLeaveHistory,
  fetchMyCompensatoryLeaveRequests,
  bulkGrantCompensatoryLeave,
  grantCompensatoryLeave,
  revokeCompensatoryLeaveGrant,
  type CreateCompensatoryLeaveRequestInput,
  type BulkGrantCompensatoryLeaveInput,
  type GrantCompensatoryLeaveInput,
  type FetchMyCompensatoryLeaveRequestsParams,
} from '../api/compensatoryLeave'

const MY_GRANTS_KEY = ['compensatory-leave', 'grants', 'mine']
const MY_REQUESTS_KEY = ['compensatory-leave', 'requests', 'mine']

export function useMyCompensatoryLeaveGrants() {
  return useQuery({ queryKey: MY_GRANTS_KEY, queryFn: fetchMyCompensatoryLeaveGrants })
}

export function useCompensatoryLeaveGrantsForUser(userId: string) {
  return useQuery({
    queryKey: ['compensatory-leave', 'grants', 'user', userId],
    queryFn: () => fetchCompensatoryLeaveGrantsForUser(userId),
    enabled: Boolean(userId),
  })
}

export function useGrantCompensatoryLeave() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: GrantCompensatoryLeaveInput) => grantCompensatoryLeave(input),
    onSuccess: (_data, input) => {
      void queryClient.invalidateQueries({ queryKey: ['compensatory-leave', 'grants', 'user', input.user_id] })
    },
  })
}

/** 代休の手動付与の一括(全部か無しか)。付与一覧を無効化する。 */
export function useBulkGrantCompensatoryLeave() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: BulkGrantCompensatoryLeaveInput) => bulkGrantCompensatoryLeave(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['compensatory-leave', 'grants'] })
    },
  })
}

export function useRevokeCompensatoryLeaveGrant() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ grantId, reason }: { grantId: string; reason?: string }) => revokeCompensatoryLeaveGrant(grantId, reason),
    onSuccess: (data) => {
      void queryClient.invalidateQueries({ queryKey: ['compensatory-leave', 'grants', 'user', data.user_id] })
    },
  })
}

export function useMyCompensatoryLeaveRequests(params: FetchMyCompensatoryLeaveRequestsParams = {}) {
  return useQuery({ queryKey: [...MY_REQUESTS_KEY, params], queryFn: () => fetchMyCompensatoryLeaveRequests(params) })
}

function useInvalidateCompensatoryLeaveRequests() {
  const queryClient = useQueryClient()

  return () => {
    void queryClient.invalidateQueries({ queryKey: MY_REQUESTS_KEY })
    void queryClient.invalidateQueries({ queryKey: MY_GRANTS_KEY })
  }
}

/** 申請の入口は設定で分かれる: 承認が必要ならワークフローの入口、不要なら直接の申請(設定と違う入口は422)。 */
export function useCreateCompensatoryLeaveRequest(approvalRequired: boolean) {
  const invalidate = useInvalidateCompensatoryLeaveRequests()

  return useMutation({
    mutationFn: (input: CreateCompensatoryLeaveRequestInput) =>
      approvalRequired ? createCompensatoryLeaveWorkflowRequest(input) : createCompensatoryLeaveRequest(input),
    onSuccess: () => invalidate(),
  })
}

export function useCancelCompensatoryLeaveRequest() {
  const invalidate = useInvalidateCompensatoryLeaveRequests()

  return useMutation({
    mutationFn: (id: string) => cancelCompensatoryLeaveRequest(id),
    onSuccess: () => invalidate(),
  })
}

export function useMyCompensatoryLeaveHistory() {
  return useQuery({ queryKey: ['compensatory-leave', 'history', 'mine'], queryFn: fetchMyCompensatoryLeaveHistory })
}

export function useCompensatoryLeaveHistoryForUser(userId: string) {
  return useQuery({
    queryKey: ['compensatory-leave', 'history', 'user', userId],
    queryFn: () => fetchCompensatoryLeaveHistoryForUser(userId),
    enabled: Boolean(userId),
  })
}

function compensatoryLeaveUsagesForUserKey(userId: string) {
  return ['compensatory-leave', 'usages', 'user', userId]
}

export function useCompensatoryLeaveUsagesForUser(userId: string) {
  return useQuery({
    queryKey: compensatoryLeaveUsagesForUserKey(userId),
    queryFn: () => fetchCompensatoryLeaveUsagesForUser(userId),
    enabled: Boolean(userId),
  })
}

/** 管理者による代休申請の取消。取消対象社員のusages一覧を再取得する。 */
export function useAdminCancelCompensatoryLeaveRequest(userId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (requestId: string) => adminCancelCompensatoryLeaveRequest(requestId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: compensatoryLeaveUsagesForUserKey(userId) })
    },
  })
}
