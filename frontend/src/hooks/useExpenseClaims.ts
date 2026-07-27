import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  addExpenseItem,
  addExpenseItemsBulk,
  approveExpenseClaim,
  cancelExpenseClaim,
  createExpenseClaim,
  deleteExpenseItem,
  fetchExpenseClaim,
  fetchExpenseClaimHistory,
  fetchExpenseClaimsToApprove,
  fetchMyExpenseClaims,
  returnExpenseClaim,
  submitExpenseClaim,
  updateExpenseItem,
  type SaveExpenseItemInput,
} from '../api/expenseClaims'

const LIST_KEY = ['expense-claims', 'mine']
const TO_APPROVE_KEY = ['expense-claims', 'to-approve']
const detailKey = (id: string) => ['expense-claims', id]
const historyKey = (id: string) => ['expense-claims', id, 'history']

export function useMyExpenseClaims() {
  return useQuery({ queryKey: LIST_KEY, queryFn: fetchMyExpenseClaims })
}

export function useExpenseClaimsToApprove() {
  return useQuery({ queryKey: TO_APPROVE_KEY, queryFn: fetchExpenseClaimsToApprove })
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

function useInvalidateClaim(id: string) {
  const queryClient = useQueryClient()
  return () => {
    void queryClient.invalidateQueries({ queryKey: detailKey(id) })
    void queryClient.invalidateQueries({ queryKey: LIST_KEY })
    void queryClient.invalidateQueries({ queryKey: TO_APPROVE_KEY })
  }
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

export function useAddExpenseItem(claimId: string) {
  const invalidate = useInvalidateClaim(claimId)

  return useMutation({
    mutationFn: (input: SaveExpenseItemInput) => addExpenseItem(claimId, input),
    onSuccess: invalidate,
  })
}

export function useAddExpenseItemsBulk(claimId: string) {
  const invalidate = useInvalidateClaim(claimId)

  return useMutation({
    mutationFn: (items: SaveExpenseItemInput[]) => addExpenseItemsBulk(claimId, items),
    onSuccess: invalidate,
  })
}

export function useUpdateExpenseItem(claimId: string) {
  const invalidate = useInvalidateClaim(claimId)

  return useMutation({
    mutationFn: ({ itemId, input }: { itemId: string; input: SaveExpenseItemInput }) =>
      updateExpenseItem(claimId, itemId, input),
    onSuccess: invalidate,
  })
}

export function useDeleteExpenseItem(claimId: string) {
  const invalidate = useInvalidateClaim(claimId)

  return useMutation({
    mutationFn: (itemId: string) => deleteExpenseItem(claimId, itemId),
    onSuccess: invalidate,
  })
}

export function useSubmitExpenseClaim(claimId: string) {
  const invalidate = useInvalidateClaim(claimId)

  return useMutation({
    mutationFn: (approverUserId: string) => submitExpenseClaim(claimId, approverUserId),
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
