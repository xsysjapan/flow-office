import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  createExpenseCategory,
  deleteExpenseCategory,
  fetchExpenseCategories,
  updateExpenseCategory,
  type SaveExpenseCategoryInput,
} from '../api/expenseCategories'

const KEY = ['expense-categories']

export function useExpenseCategories(includeInactive = false) {
  return useQuery({
    queryKey: [...KEY, includeInactive],
    queryFn: () => fetchExpenseCategories(includeInactive),
  })
}

export function useCreateExpenseCategory() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: SaveExpenseCategoryInput) => createExpenseCategory(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: KEY })
    },
  })
}

export function useUpdateExpenseCategory() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: SaveExpenseCategoryInput }) => updateExpenseCategory(id, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: KEY })
    },
  })
}

export function useDeleteExpenseCategory() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => deleteExpenseCategory(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: KEY })
    },
  })
}
