import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  applyExpenseEntryPreset,
  createExpenseEntryPreset,
  deleteExpenseEntryPreset,
  fetchExpenseEntryPreset,
  fetchExpenseEntryPresets,
  updateExpenseEntryPreset,
  type ExpenseEntryPresetFilters,
  type SaveExpenseEntryPresetInput,
} from '../api/expenseEntryPresets'

const KEY = ['expense-entry-presets']

/** 一覧・検索・ページング用。管理画面(ページング・検索あり)と、入力画面のプリセット
 *  候補表示(category_idで絞り込むだけ)の両方で使う。 */
export function useExpenseEntryPresets(filters: ExpenseEntryPresetFilters = {}) {
  return useQuery({
    queryKey: [...KEY, filters],
    queryFn: () => fetchExpenseEntryPresets(filters),
    placeholderData: keepPreviousData,
  })
}

/** プリセット編集画面向け。一覧がページングされても、編集対象がどのページにあっても
 *  取得できるよう単体取得エンドポイントを使う。 */
export function useExpenseEntryPreset(id: number | undefined) {
  return useQuery({
    queryKey: [...KEY, 'detail', id],
    queryFn: () => fetchExpenseEntryPreset(id as number),
    enabled: id !== undefined,
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
