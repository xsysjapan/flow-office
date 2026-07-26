import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as expenseCategoriesApi from '../../api/expenseCategories'
import type { ExpenseCategory } from '../../api/types'
import { ExpenseCategoryListPage } from './ExpenseCategoryListPage'

const categories: ExpenseCategory[] = [
  {
    id: 1,
    code: 'transport',
    name: '交通費',
    description: null,
    evidence_type_default: 'fact_reference_available',
    receipt_required_threshold: null,
    approval_skip_threshold: 3000,
    is_active: true,
  },
  {
    id: 2,
    code: 'supplies',
    name: '消耗品費',
    description: null,
    evidence_type_default: 'receipt_required',
    receipt_required_threshold: 0,
    approval_skip_threshold: null,
    is_active: false,
  },
]

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(expenseCategoriesApi, 'fetchExpenseCategories').mockResolvedValue(categories)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <ExpenseCategoryListPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('ExpenseCategoryListPage', () => {
  it('lists expense categories with evidence type label and thresholds', async () => {
    renderPage()

    expect(await screen.findByText('交通費')).toBeInTheDocument()
    expect(screen.getByText('消耗品費')).toBeInTheDocument()
    expect(screen.getByText('実績参照のみ')).toBeInTheDocument()
    expect(screen.getByText('レシート必須')).toBeInTheDocument()
    expect(screen.getByText('3000円')).toBeInTheDocument()
    expect(screen.getByText('有効')).toBeInTheDocument()
    expect(screen.getByText('無効')).toBeInTheDocument()
  })

  it('links to the new-expense-category page', async () => {
    renderPage()

    expect(await screen.findByRole('link', { name: '新規作成' })).toHaveAttribute(
      'href',
      '/admin/expense-categories/new',
    )
  })

  it('links a row to its edit page', async () => {
    renderPage()

    expect(await screen.findByRole('link', { name: '交通費' })).toHaveAttribute(
      'href',
      '/admin/expense-categories/1',
    )
  })
})
