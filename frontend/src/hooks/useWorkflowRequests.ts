import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  approveWorkflowRequest,
  cancelWorkflowRequest,
  createWorkflowRequest,
  fetchMyWorkflowRequests,
  fetchWorkflowRequest,
  fetchWorkflowRequestHistory,
  fetchWorkflowRequestsToApprove,
  rejectWorkflowRequest,
  returnWorkflowRequest,
  submitWorkflowRequest,
  type CreateWorkflowRequestInput,
  type FetchMyWorkflowRequestsOptions,
  type FetchWorkflowRequestsToApproveOptions,
} from '../api/workflowRequests'

const LIST_KEY = ['workflow-requests', 'mine']
const TO_APPROVE_KEY = ['workflow-requests', 'to-approve']

/**
 * 申請センター(自分の申請の横断一覧)向け。statusとsubject_typeの絞り込み・ページングに
 * 対応する(useWorkflowRequestsToApproveと同じパターン)。引数省略時は絞り込みなしの
 * 全件・1ページ目を返す(既存呼び出し元との後方互換)。
 */
export function useMyWorkflowRequests(options: FetchMyWorkflowRequestsOptions = {}, enabled = true) {
  return useQuery({
    queryKey: [...LIST_KEY, options.status ?? '', options.subjectType ?? '', options.page ?? 1],
    queryFn: () => fetchMyWorkflowRequests(options),
    enabled,
    placeholderData: keepPreviousData,
  })
}

export function useWorkflowRequestsToApprove(options: FetchWorkflowRequestsToApproveOptions = {}, enabled = true) {
  return useQuery({
    queryKey: [...TO_APPROVE_KEY, options.status ?? '', options.yearMonth ?? '', options.page ?? 1],
    queryFn: () => fetchWorkflowRequestsToApprove(options),
    enabled,
    placeholderData: keepPreviousData,
  })
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

export function useRejectWorkflowRequest() {
  const invalidate = useInvalidateWorkflowRequests()

  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) => rejectWorkflowRequest(id, reason),
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
