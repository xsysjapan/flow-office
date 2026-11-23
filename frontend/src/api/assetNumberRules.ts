import { apiFetch } from './client'

/**
 * 管理番号自動採番ルール(spec: docs/changesets/20260831-asset-management-refinement)。
 * `category`が`null`の行は`is_default: true`のデフォルトルールを表す。
 */
export interface AssetNumberRule {
  category: string | null
  prefix: string
  digit_count: number
  next_number: number
  is_active: boolean
  is_default: boolean
}

export function fetchAssetNumberRules(): Promise<AssetNumberRule[]> {
  // AppServiceProviderで`JsonResource::withoutWrapping()`のため、配列がそのまま返る(`data`で包まれない)。
  return apiFetch('/asset-number-rules')
}

export function fetchAssetNumberRuleCategories(): Promise<string[]> {
  return apiFetch<{ data: string[] }>('/asset-number-rules/categories').then((res) => res.data)
}

export interface SaveAssetNumberRuleInput {
  prefix: string
  digit_count: number
  is_active: boolean
}

export function updateAssetNumberRule(category: string, input: SaveAssetNumberRuleInput): Promise<AssetNumberRule> {
  return apiFetch(`/asset-number-rules/${encodeURIComponent(category)}`, {
    method: 'PUT',
    body: { prefix: input.prefix, digit_count: input.digit_count, is_active: input.is_active },
  })
}

export function updateDefaultAssetNumberRule(input: SaveAssetNumberRuleInput): Promise<AssetNumberRule> {
  return apiFetch('/asset-number-rules/default', {
    method: 'PUT',
    body: { prefix: input.prefix, digit_count: input.digit_count, is_active: input.is_active },
  })
}
