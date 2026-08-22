import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  addExpenseItem,
  addExpenseItemsBulk,
  approveExpenseClaim,
  cancelExpenseClaim,
  createExpenseClaim,
  deleteExpenseClaim,
  deleteExpenseItem,
  fetchExpenseClaim,
  fetchExpenseClaimHistory,
  fetchMyExpenseClaims,
  returnExpenseClaim,
  submitExpenseClaim,
  updateExpenseClaimTitle,
  updateExpenseItem,
  type SaveExpenseItemInput,
} from '../api/expenseClaims'

const LIST_KEY = ['expense-claims', 'mine']
const TO_APPROVE_KEY = ['expense-claims', 'to-approve']
const detailKey = (id: string) => ['expense-claims', id]
const historyKey = (id: string) => ['expense-claims', id, 'history']

export function useMyExpenseClaims(enabled = true) {
  return useQuery({ queryKey: LIST_KEY, queryFn: fetchMyExpenseClaims, enabled })
}

export function useExpenseClaim(id: string | undefined) {
  return useQuery({
    queryKey: detailKey(id ?? ''),
    queryFn: () => fetchExpenseClaim(id as string),
    enabled: Boolean(id),
  })
}

export function useExpenseClaimHistory(id: string | undefined) {
  return useQuery({
    queryKey: historyKey(id ?? ''),
    queryFn: () => fetchExpenseClaimHistory(id as string),
    enabled: Boolean(id),
  })
}

function invalidateClaimQueries(queryClient: ReturnType<typeof useQueryClient>, id: string) {
  void queryClient.invalidateQueries({ queryKey: detailKey(id) })
  void queryClient.invalidateQueries({ queryKey: LIST_KEY })
  void queryClient.invalidateQueries({ queryKey: TO_APPROVE_KEY })
}

function useInvalidateClaim(id: string) {
  const queryClient = useQueryClient()
  return () => invalidateClaimQueries(queryClient, id)
}

export function useCreateExpenseClaim() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => createExpenseClaim(),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: LIST_KEY })
    },
  })
}

/**
 * claimIdはmutate時の引数として受け取る(hook生成時のclaimIdをクロージャで固定しない)。
 * 経費精算の新規作成画面では、明細を初めて保存する瞬間まで下書き自体を作らないため、
 * hook呼び出し時点ではまだclaimIdが確定していない。
 */
export function useAddExpenseItem() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ claimId, input }: { claimId: string; input: SaveExpenseItemInput }) =>
      addExpenseItem(claimId, input),
    onSuccess: (_data, { claimId }) => invalidateClaimQueries(queryClient, claimId),
  })
}

export function useAddExpenseItemsBulk() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ claimId, items }: { claimId: string; items: SaveExpenseItemInput[] }) =>
      addExpenseItemsBulk(claimId, items),
    onSuccess: (_data, { claimId }) => invalidateClaimQueries(queryClient, claimId),
  })
}

export function useUpdateExpenseItem() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ claimId, itemId, input }: { claimId: string; itemId: string; input: SaveExpenseItemInput }) =>
      updateExpenseItem(claimId, itemId, input),
    onSuccess: (_data, { claimId }) => invalidateClaimQueries(queryClient, claimId),
  })
}

export function useDeleteExpenseItem() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ claimId, itemId }: { claimId: string; itemId: string }) => deleteExpenseItem(claimId, itemId),
    onSuccess: (_data, { claimId }) => invalidateClaimQueries(queryClient, claimId),
  })
}

export function useSubmitExpenseClaim(claimId: string) {
  const invalidate = useInvalidateClaim(claimId)

  return useMutation({
    mutationFn: (approverUserId?: string) => submitExpenseClaim(claimId, approverUserId),
    onSuccess: invalidate,
  })
}

export function useApproveExpenseClaim(claimId: string) {
  const invalidate = useInvalidateClaim(claimId)

  return useMutation({
    mutationFn: () => approveExpenseClaim(claimId),
    onSuccess: invalidate,
  })
}

export function useReturnExpenseClaim(claimId: string) {
  const invalidate = useInvalidateClaim(claimId)

  return useMutation({
    mutationFn: (comment: string) => returnExpenseClaim(claimId, comment),
    onSuccess: invalidate,
  })
}

export function useCancelExpenseClaim(claimId: string) {
  const invalidate = useInvalidateClaim(claimId)

  return useMutation({
    mutationFn: (reason: string) => cancelExpenseClaim(claimId, reason),
    onSuccess: invalidate,
  })
}

/** UC-X010: 不要な下書きを削除する。一覧から即実行するため、claimIdはmutate時の引数で受け取る。 */
export function useDeleteExpenseClaim() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (claimId: string) => deleteExpenseClaim(claimId),
    onSuccess: (_data, claimId) => invalidateClaimQueries(queryClient, claimId),
  })
}

/** 申請タイトル(任意項目)を設定・変更する。 */
export function useUpdateExpenseClaimTitle() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ claimId, title }: { claimId: string; title: string | null }) =>
      updateExpenseClaimTitle(claimId, title),
    onSuccess: (_data, { claimId }) => invalidateClaimQueries(queryClient, claimId),
  })
}
