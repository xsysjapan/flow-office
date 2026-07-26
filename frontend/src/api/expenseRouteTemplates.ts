import { apiFetch } from './client'
import type { ExpenseRouteTemplate, ExpenseRouteTemplateScope } from './types'

/** UC-X002/X003: 本人のpersonalテンプレートと全社共有のcompanyテンプレートをマージして返す。 */
export function fetchExpenseRouteTemplates(): Promise<ExpenseRouteTemplate[]> {
  return apiFetch('/expense-route-templates')
}

export interface SaveExpenseRouteTemplateInput {
  scope: ExpenseRouteTemplateScope
  /** personalスコープの場合のみ指定(通常はログイン中の本人)。companyスコープではundefined。 */
  employee_id?: string
  name: string
  origin: string
  destination: string
  transport_type: string
  amount: number
  category_id: number
  is_active?: boolean
}

export function createExpenseRouteTemplate(input: SaveExpenseRouteTemplateInput): Promise<ExpenseRouteTemplate> {
  return apiFetch('/expense-route-templates', { method: 'POST', body: input })
}

export function updateExpenseRouteTemplate(
  id: number,
  input: SaveExpenseRouteTemplateInput,
): Promise<ExpenseRouteTemplate> {
  return apiFetch(`/expense-route-templates/${id}`, { method: 'PUT', body: input })
}

export function deleteExpenseRouteTemplate(id: number): Promise<void> {
  return apiFetch(`/expense-route-templates/${id}`, { method: 'DELETE' })
}
