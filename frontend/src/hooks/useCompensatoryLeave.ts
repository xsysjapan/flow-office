import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  cancelCompensatoryLeaveRequest,
  createCompensatoryLeaveRequest,
  fetchCompensatoryLeaveGrantsForUser,
  fetchCompensatoryLeaveHistoryForUser,
  fetchMyCompensatoryLeaveGrants,
  fetchMyCompensatoryLeaveHistory,
  fetchMyCompensatoryLeaveRequests,
  grantCompensatoryLeave,
  revokeCompensatoryLeaveGrant,
  type CreateCompensatoryLeaveRequestInput,
  type GrantCompensatoryLeaveInput,
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

export function useRevokeCompensatoryLeaveGrant() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ grantId, reason }: { grantId: string; reason?: string }) => revokeCompensatoryLeaveGrant(grantId, reason),
    onSuccess: (data) => {
      void queryClient.invalidateQueries({ queryKey: ['compensatory-leave', 'grants', 'user', data.user_id] })
    },
  })
}

export function useMyCompensatoryLeaveRequests() {
  return useQuery({ queryKey: MY_REQUESTS_KEY, queryFn: fetchMyCompensatoryLeaveRequests })
}

function useInvalidateCompensatoryLeaveRequests() {
  const queryClient = useQueryClient()

  return () => {
    void queryClient.invalidateQueries({ queryKey: MY_REQUESTS_KEY })
    void queryClient.invalidateQueries({ queryKey: MY_GRANTS_KEY })
  }
}

export function useCreateCompensatoryLeaveRequest() {
  const invalidate = useInvalidateCompensatoryLeaveRequests()

  return useMutation({
    mutationFn: (input: CreateCompensatoryLeaveRequestInput) => createCompensatoryLeaveRequest(input),
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
