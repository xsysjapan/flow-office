import { apiFetch } from './client'

/**
 * 管理番号自動採番ルール(spec: docs/changesets/20260831-asset-management-refinement)。
 * `category`が`null`の行は`isDefault: true`のデフォルトルールを表す。
 */
export interface AssetNumberRule {
  category: string | null
  prefix: string
  digitCount: number
  nextNumber: number
  enabled: boolean
  isDefault: boolean
}

export function fetchAssetNumberRules(): Promise<AssetNumberRule[]> {
  return apiFetch('/asset-number-rules')
}

export function fetchAssetNumberRuleCategories(): Promise<string[]> {
  return apiFetch('/asset-number-rules/categories')
}

export interface SaveAssetNumberRuleInput {
  prefix: string
  digitCount: number
  enabled: boolean
}

export function updateAssetNumberRule(category: string, input: SaveAssetNumberRuleInput): Promise<AssetNumberRule> {
  return apiFetch(`/asset-number-rules/${encodeURIComponent(category)}`, {
    method: 'PUT',
    body: { prefix: input.prefix, digit_count: input.digitCount, enabled: input.enabled },
  })
}

export function updateDefaultAssetNumberRule(input: SaveAssetNumberRuleInput): Promise<AssetNumberRule> {
  return apiFetch('/asset-number-rules/default', {
    method: 'PUT',
    body: { prefix: input.prefix, digit_count: input.digitCount, enabled: input.enabled },
  })
}
