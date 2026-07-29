import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as expenseCategoriesApi from '../../api/expenseCategories'
import * as expenseEntryPresetsApi from '../../api/expenseEntryPresets'
import type { ExpenseCategory, ExpenseEntryPreset } from '../../api/types'
import { ExpenseEntryPresetEditPage } from './ExpenseEntryPresetEditPage'

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
  {
    id: 2,
    code: 'lodging',
    name: '宿泊費',
    description: null,
    evidence_type_default: 'receipt_required',
    entry_mode: 'single',
    field_definitions: null,
    receipt_required_threshold: 0,
    approval_skip_threshold: null,
    is_active: true,
  },
]

const preset: ExpenseEntryPreset = {
  id: 1,
  visibility: 'personal',
  owner_user_id: 'applicant-1',
  name: '自宅⇔会社',
  description: null,
  preset_type: 'single_item',
  definition: [{ category_id: 1, description: '自宅 → 会社(電車)', amount: 420, payment_bearer: 'employee' }],
  is_active: true,
  usage_count: 3,
  last_used_at: null,
  created_by: 'applicant-1',
}

function renderPage(initialPath: string, presets: ExpenseEntryPreset[] = [preset]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(expenseEntryPresetsApi, 'fetchExpenseEntryPresets').mockResolvedValue(presets)
  vi.spyOn(expenseCategoriesApi, 'fetchExpenseCategories').mockResolvedValue(categories)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialPath]}>
        <Routes>
          <Route path="/expenses/presets/:id" element={<ExpenseEntryPresetEditPage />} />
          <Route path="/expenses/presets" element={<p>入力プリセット一覧ページ</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('ExpenseEntryPresetEditPage', () => {
  it('starts blank in create mode with one empty item row', async () => {
    renderPage('/expenses/presets/new')

    expect(await screen.findByLabelText('名称')).toHaveValue('')
    expect(screen.getByRole('button', { name: '保存する' })).toBeDisabled()
  })

  it('prefills the form in edit mode', async () => {
    renderPage('/expenses/presets/1')

    expect(await screen.findByLabelText('名称')).toHaveValue('自宅⇔会社')
    expect(screen.getByLabelText('1番目の明細の内容の初期値')).toHaveValue('自宅 → 会社(電車)')
    expect(screen.getByLabelText('1番目の明細の金額の初期値')).toHaveValue(420)
  })

  it('creates a new personal preset with one item and navigates back to the list', async () => {
    const createPreset = vi.spyOn(expenseEntryPresetsApi, 'createExpenseEntryPreset').mockResolvedValue({
      ...preset,
      id: 2,
      name: '本社⇔A社',
    })
    renderPage('/expenses/presets/new')

    await userEvent.type(await screen.findByLabelText('名称'), '本社⇔A社')
    await userEvent.selectOptions(screen.getByLabelText('1番目の明細の経費区分'), '交通費')
    await userEvent.type(screen.getByLabelText('1番目の明細の内容の初期値'), '本社 → A社(電車)')
    await userEvent.type(screen.getByLabelText('1番目の明細の金額の初期値'), '400')

    await userEvent.click(screen.getByRole('button', { name: '保存する' }))

    await waitFor(() =>
      expect(createPreset).toHaveBeenCalledWith(
        expect.objectContaining({
          visibility: 'personal',
          name: '本社⇔A社',
          preset_type: 'single_item',
          definition: [
            expect.objectContaining({ category_id: 1, description: '本社 → A社(電車)', amount: 400 }),
          ],
        }),
      ),
    )
    expect(await screen.findByText('入力プリセット一覧ページ')).toBeInTheDocument()
  })

  it('adds and removes definition item rows', async () => {
    renderPage('/expenses/presets/new')

    await screen.findByLabelText('名称')
    expect(screen.queryByLabelText('2番目の明細の経費区分')).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: '明細を追加' }))
    expect(screen.getByLabelText('2番目の明細の経費区分')).toBeInTheDocument()

    const deleteButtons = screen.getAllByRole('button', { name: '削除' })
    await userEvent.click(deleteButtons[1])
    expect(screen.queryByLabelText('2番目の明細の経費区分')).not.toBeInTheDocument()
  })
})
