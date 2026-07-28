import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as expenseCategoriesApi from '../../api/expenseCategories'
import * as expenseRouteTemplatesApi from '../../api/expenseRouteTemplates'
import type { ExpenseCategory, ExpenseRouteTemplate } from '../../api/types'
import { ExpenseRouteTemplateEditPage } from './ExpenseRouteTemplateEditPage'

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

const template: ExpenseRouteTemplate = {
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
}

function renderPage(initialPath: string, templates: ExpenseRouteTemplate[] = [template]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(expenseRouteTemplatesApi, 'fetchExpenseRouteTemplates').mockResolvedValue(templates)
  vi.spyOn(expenseCategoriesApi, 'fetchExpenseCategories').mockResolvedValue(categories)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialPath]}>
        <Routes>
          <Route path="/admin/expense-route-templates/:id" element={<ExpenseRouteTemplateEditPage />} />
          <Route path="/admin/expense-route-templates" element={<p>移動区間テンプレート一覧ページ</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('ExpenseRouteTemplateEditPage', () => {
  it('starts blank in create mode with the first category preselected', async () => {
    renderPage('/admin/expense-route-templates/new')

    expect(await screen.findByLabelText('名称')).toHaveValue('')
    await waitFor(() => expect(screen.getByLabelText('対象経費区分')).toHaveValue('1'))
  })

  it('prefills the form in edit mode', async () => {
    renderPage('/admin/expense-route-templates/1')

    expect(await screen.findByLabelText('名称')).toHaveValue('本社-横浜支社')
    expect(screen.getByLabelText('出発地')).toHaveValue('本社')
    expect(screen.getByLabelText('到着地')).toHaveValue('横浜支社')
    expect(screen.getByLabelText('金額')).toHaveValue(640)
  })

  it('creates a new company-scoped route template and navigates back to the list', async () => {
    vi.spyOn(expenseRouteTemplatesApi, 'createExpenseRouteTemplate').mockResolvedValue({
      ...template,
      id: 2,
      name: '本社-大阪支社',
    })
    renderPage('/admin/expense-route-templates/new')

    await userEvent.type(await screen.findByLabelText('名称'), '本社-大阪支社')
    await userEvent.type(screen.getByLabelText('出発地'), '本社')
    await userEvent.type(screen.getByLabelText('到着地'), '大阪支社')
    await userEvent.type(screen.getByLabelText('金額'), '5000')
    await userEvent.click(screen.getByRole('button', { name: '保存する' }))

    await waitFor(() =>
      expect(expenseRouteTemplatesApi.createExpenseRouteTemplate).toHaveBeenCalledWith({
        scope: 'company',
        name: '本社-大阪支社',
        origin: '本社',
        destination: '大阪支社',
        transport_type: 'train',
        amount: 5000,
        category_id: 1,
        is_active: true,
      }),
    )
    expect(await screen.findByText('移動区間テンプレート一覧ページ')).toBeInTheDocument()
  })

  it('updates an existing route template', async () => {
    vi.spyOn(expenseRouteTemplatesApi, 'updateExpenseRouteTemplate').mockResolvedValue(template)
    renderPage('/admin/expense-route-templates/1')

    await screen.findByDisplayValue('本社-横浜支社')
    await userEvent.click(screen.getByRole('button', { name: '保存する' }))

    await waitFor(() =>
      expect(expenseRouteTemplatesApi.updateExpenseRouteTemplate).toHaveBeenCalledWith(1, {
        scope: 'company',
        name: '本社-横浜支社',
        origin: '本社',
        destination: '横浜支社',
        transport_type: 'train',
        amount: 640,
        category_id: 1,
        is_active: true,
      }),
    )
  })
})
