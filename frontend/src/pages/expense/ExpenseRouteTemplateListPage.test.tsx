import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as expenseCategoriesApi from '../../api/expenseCategories'
import * as expenseRouteTemplatesApi from '../../api/expenseRouteTemplates'
import type { ExpenseCategory, ExpenseRouteTemplate } from '../../api/types'
import { ExpenseRouteTemplateListPage } from './ExpenseRouteTemplateListPage'

const categories: ExpenseCategory[] = [
  {
    id: 1,
    code: 'transport',
    name: '交通費',
    description: null,
    evidence_type_default: 'fact_reference_available',
    entry_mode: 'batch',
    field_definitions: null,
    receipt_required_threshold: null,
    approval_skip_threshold: null,
    is_active: true,
  },
]

const templates: ExpenseRouteTemplate[] = [
  {
    id: 1,
    scope: 'company',
    employee_id: null,
    name: '本社-横浜支社',
    origin: '本社',
    destination: '横浜支社',
    transport_type: 'train',
    amount: 640,
    category_id: 1,
    is_active: true,
  },
  {
    id: 2,
    scope: 'personal',
    employee_id: 'emp-001',
    name: '自宅-本社',
    origin: '自宅',
    destination: '本社',
    transport_type: 'train',
    amount: 480,
    category_id: 1,
    is_active: true,
  },
]

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(expenseRouteTemplatesApi, 'fetchExpenseRouteTemplates').mockResolvedValue(templates)
  vi.spyOn(expenseCategoriesApi, 'fetchExpenseCategories').mockResolvedValue(categories)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <ExpenseRouteTemplateListPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('ExpenseRouteTemplateListPage', () => {
  it('lists only company-scoped templates', async () => {
    renderPage()

    expect(await screen.findByText('本社-横浜支社')).toBeInTheDocument()
    expect(screen.queryByText('自宅-本社')).not.toBeInTheDocument()
  })

  it('shows origin, destination and resolved category name', async () => {
    renderPage()

    expect(await screen.findByText('本社→横浜支社')).toBeInTheDocument()
    expect(screen.getByText('交通費')).toBeInTheDocument()
    expect(screen.getByText('640円')).toBeInTheDocument()
  })

  it('links to the new-route-template page', async () => {
    renderPage()

    expect(await screen.findByRole('link', { name: '新規作成' })).toHaveAttribute(
      'href',
      '/admin/expense-route-templates/new',
    )
  })
})
