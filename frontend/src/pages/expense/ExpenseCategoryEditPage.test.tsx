import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as expenseCategoriesApi from '../../api/expenseCategories'
import type { ExpenseCategory } from '../../api/types'
import { ExpenseCategoryEditPage } from './ExpenseCategoryEditPage'

const transportCategory: ExpenseCategory = {
  id: 1,
  code: 'transportation',
  name: '交通費',
  description: null,
  evidence_type_default: 'fact_reference_available',
  entry_mode: 'batch',
  field_definitions: null,
  receipt_required_threshold: null,
  approval_skip_threshold: 3000,
  is_active: true,
}

function renderPage(initialPath: string, categories: ExpenseCategory[] = [transportCategory]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(expenseCategoriesApi, 'fetchExpenseCategories').mockResolvedValue(categories)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialPath]}>
        <Routes>
          <Route path="/admin/expense-categories/:id" element={<ExpenseCategoryEditPage />} />
          <Route path="/admin/expense-categories" element={<p>経費区分一覧ページ</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('ExpenseCategoryEditPage', () => {
  it('starts blank in create mode', async () => {
    renderPage('/admin/expense-categories/new')

    expect(await screen.findByLabelText('コード')).toHaveValue('')
    expect(screen.getByLabelText('コード')).not.toBeDisabled()
  })

  it('prefills the form and disables the code field in edit mode', async () => {
    renderPage('/admin/expense-categories/1')

    expect(await screen.findByLabelText('コード')).toHaveValue('transportation')
    expect(screen.getByLabelText('コード')).toBeDisabled()
    expect(screen.getByLabelText('名称')).toHaveValue('交通費')
    expect(screen.getByLabelText('承認省略しきい値(円・任意)')).toHaveValue(3000)
    expect(screen.getByLabelText('入力方式')).toHaveValue('batch')
  })

  it('creates a new expense category and navigates back to the list', async () => {
    vi.spyOn(expenseCategoriesApi, 'createExpenseCategory').mockResolvedValue({
      ...transportCategory,
      id: 2,
      code: 'supplies',
    })
    renderPage('/admin/expense-categories/new')

    await userEvent.type(await screen.findByLabelText('コード'), 'supplies')
    await userEvent.type(screen.getByLabelText('名称'), '消耗品費')
    await userEvent.click(screen.getByRole('button', { name: '保存する' }))

    await waitFor(() =>
      expect(expenseCategoriesApi.createExpenseCategory).toHaveBeenCalledWith({
        code: 'supplies',
        name: '消耗品費',
        description: undefined,
        evidence_type_default: 'fact_reference_available',
        entry_mode: 'single',
        field_definitions: null,
        receipt_required_threshold: undefined,
        approval_skip_threshold: undefined,
        is_active: true,
      }),
    )
    expect(await screen.findByText('経費区分一覧ページ')).toBeInTheDocument()
  })

  it('adds a field definition row and includes it in the create payload', async () => {
    vi.spyOn(expenseCategoriesApi, 'createExpenseCategory').mockResolvedValue({
      ...transportCategory,
      id: 3,
      code: 'transport2',
    })
    renderPage('/admin/expense-categories/new')

    await userEvent.type(await screen.findByLabelText('コード'), 'transport2')
    await userEvent.type(screen.getByLabelText('名称'), '交通費2')
    await userEvent.click(screen.getByRole('button', { name: '項目を追加' }))
    await userEvent.type(screen.getByLabelText('1番目の項目のキー'), 'origin')
    await userEvent.type(screen.getByLabelText('1番目の項目の表示名'), '出発地')

    await userEvent.click(screen.getByRole('button', { name: '保存する' }))

    await waitFor(() =>
      expect(expenseCategoriesApi.createExpenseCategory).toHaveBeenCalledWith(
        expect.objectContaining({
          field_definitions: [{ key: 'origin', label: '出発地', type: 'text', required: false }],
        }),
      ),
    )
  })

  it('updates an existing expense category', async () => {
    vi.spyOn(expenseCategoriesApi, 'updateExpenseCategory').mockResolvedValue(transportCategory)
    renderPage('/admin/expense-categories/1')

    await screen.findByDisplayValue('交通費')
    await userEvent.click(screen.getByRole('button', { name: '保存する' }))

    await waitFor(() =>
      expect(expenseCategoriesApi.updateExpenseCategory).toHaveBeenCalledWith(1, {
        code: 'transportation',
        name: '交通費',
        description: undefined,
        evidence_type_default: 'fact_reference_available',
        entry_mode: 'batch',
        field_definitions: null,
        receipt_required_threshold: undefined,
        approval_skip_threshold: 3000,
        is_active: true,
      }),
    )
  })
})
