import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  fetchAssetNumberRuleCategories,
  fetchAssetNumberRules,
  updateAssetNumberRule,
  updateDefaultAssetNumberRule,
  type AssetNumberRule,
  type SaveAssetNumberRuleInput,
} from '../api/assetNumberRules'

const RULES_KEY = ['asset-number-rules']
const CATEGORIES_KEY = ['asset-number-rules', 'categories']

export function useAssetNumberRules() {
  return useQuery({ queryKey: RULES_KEY, queryFn: fetchAssetNumberRules })
}

/** 登録画面のカテゴリ補完に使う候補一覧(既存`assets.category`とルール登録済みカテゴリのUNION)。 */
export function useAssetNumberRuleCategories() {
  return useQuery({ queryKey: CATEGORIES_KEY, queryFn: fetchAssetNumberRuleCategories })
}

export function useUpdateAssetNumberRule() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ category, input }: { category: string; input: SaveAssetNumberRuleInput }) =>
      updateAssetNumberRule(category, input),
    onSuccess: (rule: AssetNumberRule) => {
      void queryClient.invalidateQueries({ queryKey: RULES_KEY })
      if (rule.category) void queryClient.invalidateQueries({ queryKey: CATEGORIES_KEY })
    },
  })
}

export function useUpdateDefaultAssetNumberRule() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: SaveAssetNumberRuleInput) => updateDefaultAssetNumberRule(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: RULES_KEY })
    },
  })
}
