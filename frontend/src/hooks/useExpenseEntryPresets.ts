import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  applyExpenseEntryPreset,
  createExpenseEntryPreset,
  deleteExpenseEntryPreset,
  fetchExpenseEntryPresets,
  updateExpenseEntryPreset,
  type SaveExpenseEntryPresetInput,
} from '../api/expenseEntryPresets'

const KEY = ['expense-entry-presets']

export function useExpenseEntryPresets() {
  return useQuery({
    queryKey: KEY,
    queryFn: () => fetchExpenseEntryPresets(),
  })
}

export function useCreateExpenseEntryPreset() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: SaveExpenseEntryPresetInput) => createExpenseEntryPreset(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: KEY })
    },
  })
}

export function useUpdateExpenseEntryPreset() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: SaveExpenseEntryPresetInput }) =>
      updateExpenseEntryPreset(id, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: KEY })
    },
  })
}

export function useDeleteExpenseEntryPreset() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => deleteExpenseEntryPreset(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: KEY })
    },
  })
}

/** プリセット適用時に使用回数を記録する。UIの明細下書き生成自体は呼び出し側(ページ)が行う。 */
export function useApplyExpenseEntryPreset() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => applyExpenseEntryPreset(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: KEY })
    },
  })
}
