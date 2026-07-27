import { apiFetch } from './client'
import type {
  ExpenseClaim,
  ExpenseClaimHistoryEntry,
  ExpenseEvidenceType,
  ExpenseFactReferenceType,
  ExpenseItem,
  Paginated,
} from './types'

/** UC-X010: 自分の経費精算一覧。 */
export function fetchMyExpenseClaims(): Promise<Paginated<ExpenseClaim>> {
  return apiFetch('/expense-claims/mine')
}

/** UC-X011: 自分が承認者に指定されている経費精算一覧。 */
export function fetchExpenseClaimsToApprove(): Promise<Paginated<ExpenseClaim>> {
  return apiFetch('/expense-claims/to-approve')
}

export function fetchExpenseClaim(id: string): Promise<ExpenseClaim> {
  return apiFetch(`/expense-claims/${id}`)
}

export interface CreateExpenseClaimInput {
  period_from: string
  period_to: string
}

export function createExpenseClaim(input: CreateExpenseClaimInput): Promise<ExpenseClaim> {
  return apiFetch('/expense-claims', { method: 'POST', body: input })
}

export interface SaveExpenseItemInput {
  category_id: number
  usage_date: string
  /** 内容(自由記述)。交通費の場合は「出発地 → 到着地(手段)」形式の1行テキストをUI側で整形して設定する。 */
  description?: string
  amount: number
  project_id?: string
  evidence_type?: ExpenseEvidenceType
  fact_reference_type?: ExpenseFactReferenceType
  fact_reference_id?: string
  commuting_deduction_amount?: number
}

export function addExpenseItem(claimId: string, input: SaveExpenseItemInput): Promise<ExpenseItem> {
  return apiFetch(`/expense-claims/${claimId}/items`, { method: 'POST', body: input })
}

/** UC-X006/X007/X008: 表形式一括入力・移動経路分解・テンプレート複数日生成のいずれも
 *  複数明細をまとめて送るこのエンドポイントに集約する。 */
export function addExpenseItemsBulk(claimId: string, items: SaveExpenseItemInput[]): Promise<ExpenseItem[]> {
  return apiFetch(`/expense-claims/${claimId}/items/bulk`, { method: 'POST', body: { items } })
}

export function updateExpenseItem(
  claimId: string,
  itemId: string,
  input: SaveExpenseItemInput,
): Promise<ExpenseItem> {
  return apiFetch(`/expense-claims/${claimId}/items/${itemId}`, { method: 'PUT', body: input })
}

export function deleteExpenseItem(claimId: string, itemId: string): Promise<void> {
  return apiFetch(`/expense-claims/${claimId}/items/${itemId}`, { method: 'DELETE' })
}

export function submitExpenseClaim(claimId: string, approverUserId: string): Promise<ExpenseClaim> {
  return apiFetch(`/expense-claims/${claimId}/submit`, {
    method: 'POST',
    body: { approver_user_id: approverUserId },
  })
}

export function approveExpenseClaim(claimId: string): Promise<ExpenseClaim> {
  return apiFetch(`/expense-claims/${claimId}/approve`, { method: 'POST' })
}

export function returnExpenseClaim(claimId: string, comment: string): Promise<ExpenseClaim> {
  return apiFetch(`/expense-claims/${claimId}/return`, { method: 'POST', body: { comment } })
}

export function cancelExpenseClaim(claimId: string, reason: string): Promise<ExpenseClaim> {
  return apiFetch(`/expense-claims/${claimId}/cancel`, { method: 'POST', body: { reason } })
}

export function fetchExpenseClaimHistory(claimId: string): Promise<ExpenseClaimHistoryEntry[]> {
  return apiFetch(`/expense-claims/${claimId}/history`)
}
