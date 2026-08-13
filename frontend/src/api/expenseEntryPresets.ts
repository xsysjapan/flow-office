import { apiFetch } from './client'
import type { ExpenseEntryPreset, ExpenseEntryPresetType, ExpenseEntryPresetVisibility, Paginated } from './types'

export interface ExpenseEntryPresetFilters {
  /** 名称のあいまい検索。 */
  q?: string
  /** この経費区分を含む明細を持つプリセットだけに絞り込む(definition item側のcategory_idで判定)。 */
  category_id?: number
  page?: number
  perPage?: number
}

/** 「経費精算機能 設計・実装指示書」9〜10: 本人のpersonalプリセットと全社共有(company)・
 *  システム標準(system)プリセットをマージしてページングで返す。 */
export function fetchExpenseEntryPresets(filters: ExpenseEntryPresetFilters = {}): Promise<Paginated<ExpenseEntryPreset>> {
  return apiFetch('/expense-entry-presets', {
    query: { q: filters.q, category_id: filters.category_id, page: filters.page, per_page: filters.perPage },
  })
}

export function fetchExpenseEntryPreset(id: number): Promise<ExpenseEntryPreset> {
  return apiFetch(`/expense-entry-presets/${id}`)
}

export interface ExpenseEntryPresetDefinitionItemInput {
  category_id: number
  description?: string
  amount?: number
  payment_bearer?: string
  attributes?: Record<string, unknown>
  payee?: string
  content?: string
  participants?: string
  participant_count?: number
  departure?: string
  destination?: string
}

export interface SaveExpenseEntryPresetInput {
  visibility: ExpenseEntryPresetVisibility
  name: string
  description?: string
  preset_type: ExpenseEntryPresetType
  definition: ExpenseEntryPresetDefinitionItemInput[]
  is_active?: boolean
}

export function createExpenseEntryPreset(input: SaveExpenseEntryPresetInput): Promise<ExpenseEntryPreset> {
  return apiFetch('/expense-entry-presets', { method: 'POST', body: input })
}

export function updateExpenseEntryPreset(
  id: number,
  input: SaveExpenseEntryPresetInput,
): Promise<ExpenseEntryPreset> {
  return apiFetch(`/expense-entry-presets/${id}`, { method: 'PUT', body: input })
}

export function deleteExpenseEntryPreset(id: number): Promise<void> {
  return apiFetch(`/expense-entry-presets/${id}`, { method: 'DELETE' })
}

/** 適用回数を記録する(お気に入り・最近使った表示のため)。明細下書きへの変換はフロント側で行う。 */
export function applyExpenseEntryPreset(id: number): Promise<ExpenseEntryPreset> {
  return apiFetch(`/expense-entry-presets/${id}/apply`, { method: 'POST' })
}
