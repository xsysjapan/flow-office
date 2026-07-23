import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  approveWorkflowRequest,
  cancelWorkflowRequest,
  createWorkflowRequest,
  fetchMyWorkflowRequests,
  fetchWorkflowRequest,
  fetchWorkflowRequestHistory,
  fetchWorkflowRequestsToApprove,
  returnWorkflowRequest,
  submitWorkflowRequest,
  type CreateWorkflowRequestInput,
} from '../api/workflowRequests'

const LIST_KEY = ['workflow-requests', 'mine']
const TO_APPROVE_KEY = ['workflow-requests', 'to-approve']

export function useMyWorkflowRequests() {
  return useQuery({ queryKey: LIST_KEY, queryFn: fetchMyWorkflowRequests })
}

export function useWorkflowRequestsToApprove() {
  return useQuery({ queryKey: TO_APPROVE_KEY, queryFn: fetchWorkflowRequestsToApprove })
}

export function useWorkflowRequest(id: string) {
  return useQuery({
    queryKey: ['workflow-requests', id],
    queryFn: () => fetchWorkflowRequest(id),
    enabled: Boolean(id),
  })
}

function useInvalidateWorkflowRequests() {
  const queryClient = useQueryClient()

  return (id?: string) => {
    void queryClient.invalidateQueries({ queryKey: LIST_KEY })
    void queryClient.invalidateQueries({ queryKey: TO_APPROVE_KEY })
    if (id !== undefined) {
      void queryClient.invalidateQueries({ queryKey: ['workflow-requests', id] })
    }
  }
}

export function useCreateWorkflowRequest() {
  const invalidate = useInvalidateWorkflowRequests()

  return useMutation({
    mutationFn: (input: CreateWorkflowRequestInput) => createWorkflowRequest(input),
    onSuccess: () => invalidate(),
  })
}

export function useSubmitWorkflowRequest() {
  const invalidate = useInvalidateWorkflowRequests()

  return useMutation({
    mutationFn: ({ id, approverUserId }: { id: string; approverUserId?: string }) =>
      submitWorkflowRequest(id, approverUserId),
    onSuccess: (_data, { id }) => invalidate(id),
  })
}

export function useApproveWorkflowRequest() {
  const invalidate = useInvalidateWorkflowRequests()

  return useMutation({
    mutationFn: (id: string) => approveWorkflowRequest(id),
    onSuccess: (_data, id) => invalidate(id),
  })
}

export function useReturnWorkflowRequest() {
  const invalidate = useInvalidateWorkflowRequests()

  return useMutation({
    mutationFn: ({ id, comment }: { id: string; comment: string }) => returnWorkflowRequest(id, comment),
    onSuccess: (_data, { id }) => invalidate(id),
  })
}

export function useWorkflowRequestHistory(id: string) {
  return useQuery({
    queryKey: ['workflow-requests', id, 'history'],
    queryFn: () => fetchWorkflowRequestHistory(id),
    enabled: Boolean(id),
  })
}

export function useCancelWorkflowRequest() {
  const invalidate = useInvalidateWorkflowRequests()

  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) => cancelWorkflowRequest(id, reason),
    onSuccess: (_data, { id }) => invalidate(id),
  })
}
