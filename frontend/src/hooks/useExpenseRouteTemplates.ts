import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  createExpenseRouteTemplate,
  deleteExpenseRouteTemplate,
  fetchExpenseRouteTemplates,
  updateExpenseRouteTemplate,
  type SaveExpenseRouteTemplateInput,
} from '../api/expenseRouteTemplates'

const KEY = ['expense-route-templates']

export function useExpenseRouteTemplates() {
  return useQuery({
    queryKey: KEY,
    queryFn: () => fetchExpenseRouteTemplates(),
  })
}

export function useCreateExpenseRouteTemplate() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: SaveExpenseRouteTemplateInput) => createExpenseRouteTemplate(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: KEY })
    },
  })
}

export function useUpdateExpenseRouteTemplate() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: SaveExpenseRouteTemplateInput }) =>
      updateExpenseRouteTemplate(id, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: KEY })
    },
  })
}

export function useDeleteExpenseRouteTemplate() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => deleteExpenseRouteTemplate(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: KEY })
    },
  })
}
