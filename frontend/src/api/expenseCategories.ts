import { apiFetch } from './client'
import type { ExpenseCategory, ExpenseEvidenceType } from './types'

/** UC-X001: 経費区分マスタ。 */
export function fetchExpenseCategories(includeInactive = false): Promise<ExpenseCategory[]> {
  return apiFetch('/expense-categories', { query: { include_inactive: includeInactive || undefined } })
}

export interface SaveExpenseCategoryInput {
  code: string
  name: string
  description?: string
  evidence_type_default: ExpenseEvidenceType
  receipt_required_threshold?: number
  approval_skip_threshold?: number
  is_active?: boolean
}

export function createExpenseCategory(input: SaveExpenseCategoryInput): Promise<ExpenseCategory> {
  return apiFetch('/expense-categories', { method: 'POST', body: input })
}

export function updateExpenseCategory(id: number, input: SaveExpenseCategoryInput): Promise<ExpenseCategory> {
  return apiFetch(`/expense-categories/${id}`, { method: 'PUT', body: input })
}

export function deleteExpenseCategory(id: number): Promise<void> {
  return apiFetch(`/expense-categories/${id}`, { method: 'DELETE' })
}
