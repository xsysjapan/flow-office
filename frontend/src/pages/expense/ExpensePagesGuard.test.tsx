import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { Route, Routes, useLocation } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../../api/attendance'
import * as expenseCategoriesApi from '../../api/expenseCategories'
import * as expenseClaimsApi from '../../api/expenseClaims'
import * as expenseEntryPresetsApi from '../../api/expenseEntryPresets'
import * as usersApi from '../../api/users'
import type { ExpenseCategory, ExpenseClaim, ExpenseEntryPreset } from '../../api/types'
import { AppSettingsContext } from '../../contexts/AppSettingsContext'
import { renderWithGuard } from '../../test/renderWithGuard'
import { ExpenseCategoryEditPage } from './ExpenseCategoryEditPage'
import { ExpenseClaimNewPage } from './ExpenseClaimNewPage'
import { ExpenseEntryPresetEditPage } from './ExpenseEntryPresetEditPage'

vi.mock('../../lib/notify', () => ({ notify: { success: vi.fn() } }))
vi.mock('../../auth/useAuth', () => ({ useAuth: () => ({ user: { id: 'applicant-1' } }) }))

const transport: ExpenseCategory = {
  id: 1,
  code: 'transportation',
  name: '交通費',
  description: null,
  evidence_type_default: 'fact_reference_available',
  entry_mode: 'batch',
  field_definitions: null,
  receipt_required_threshold: null,
  approval_skip_threshold: null,
  is_active: true,
}

const preset: ExpenseEntryPreset = {
  id: 1,
  visibility: 'personal',
  owner_user_id: 'applicant-1',
  name: '自宅⇔会社',
  description: null,
  preset_type: 'single_item',
  definition: [{ category_id: 1, description: '自宅 → 会社', amount: 420, payment_bearer: 'employee' }],
  is_active: true,
  usage_count: 3,
  last_used_at: null,
  created_by: 'applicant-1',
}

function SearchProbe() {
  return <p data-testid="search">{useLocation().search}</p>
}

function withQuery(children: ReactNode) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
}

function draftClaim(): ExpenseClaim {
  return {
    id: 'claim-1',
    employee_id: 'applicant-1',
    period_from: null,
    period_to: null,
    status: 'draft',
    approver_user_id: null,
    total_amount: 0,
    submitted_at: null,
    approved_at: null,
    items: [
      {
        id: 'item-1',
        category_id: 1,
        usage_date: '2026-07-10',
        description: 'x',
        amount: 100,
        project_id: null,
        evidence_type: 'fact_reference_available',
        fact_reference_type: null,
        fact_reference_id: null,
        commuting_deduction_amount: null,
      },
    ],
  }
}

function renderClaim(initialPath: string) {
  vi.spyOn(expenseCategoriesApi, 'fetchExpenseCategories').mockResolvedValue([transport])
  vi.spyOn(expenseEntryPresetsApi, 'fetchExpenseEntryPresets').mockResolvedValue({
    data: [],
    meta: { current_page: 1, last_page: 1, total: 0 },
    links: { next: null, prev: null },
  })
  vi.spyOn(attendanceApi, 'fetchWeek').mockResolvedValue([])
  vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue({
    data: [],
    meta: { current_page: 1, last_page: 1, total: 0 },
    links: { next: null, prev: null },
  })
  return renderWithGuard(
    withQuery(
      <AppSettingsContext.Provider
        value={{
          systemSettings: { expense_claim_requires_approval: false } as never,
          isLoading: false,
        }}
      >
        <Routes>
          <Route
            path="/expenses/new"
            element={
              <>
                <ExpenseClaimNewPage />
                <SearchProbe />
              </>
            }
          />
          <Route path="/expenses/:id/edit" element={<ExpenseClaimNewPage />} />
          <Route path="/expenses/:id" element={<p>経費精算詳細ページ</p>} />
        </Routes>
      </AppSettingsContext.Provider>,
    ),
    { initialEntries: [initialPath] },
  )
}

describe('expense pages leave guard', () => {
  afterEach(() => vi.restoreAllMocks())

  it('ExpenseClaimNewPage: submit with unsaved row input navigates without the confirm dialog', async () => {
    vi.spyOn(expenseClaimsApi, 'fetchExpenseClaim').mockResolvedValue(draftClaim())
    vi.spyOn(expenseClaimsApi, 'submitExpenseClaim').mockResolvedValue(draftClaim())
    renderClaim('/expenses/claim-1/edit')

    await userEvent.click(await screen.findByRole('button', { name: '交通費' }))
    await userEvent.click(await screen.findByRole('button', { name: '行を追加' }))
    await userEvent.type(screen.getByLabelText('1行目の金額'), '300')
    await userEvent.click(screen.getByRole('button', { name: '申請する' }))

    expect(await screen.findByText('経費精算詳細ページ')).toBeInTheDocument()
    expect(screen.queryByText('保存していない変更があります')).not.toBeInTheDocument()
  })

  it('ExpenseClaimNewPage: a query-only step back with typed input shows the confirm dialog', async () => {
    renderClaim('/expenses/new?mode=bulk')

    await userEvent.type(await screen.findByLabelText('タイトル'), '出張精算')
    await userEvent.click(screen.getByRole('button', { name: '登録方法の選択に戻る' }))

    expect(await screen.findByText('保存していない変更があります')).toBeInTheDocument()
  })

  it('ExpenseClaimNewPage: step back with input and 編集を続ける keeps both the URL and the step', async () => {
    renderClaim('/expenses/new?mode=bulk')

    await userEvent.type(await screen.findByLabelText('タイトル'), '出張精算')
    await userEvent.click(screen.getByRole('button', { name: '登録方法の選択に戻る' }))
    await userEvent.click(await screen.findByRole('button', { name: '編集を続ける' }))

    expect(screen.queryByText('保存していない変更があります')).not.toBeInTheDocument()
    expect(screen.getByTestId('search')).toHaveTextContent('?mode=bulk')
    expect(screen.getByLabelText('タイトル')).toHaveValue('出張精算')
  })

  it('ExpenseClaimNewPage: step back with input and 編集をやめる moves both the URL and the step (one dialog)', async () => {
    renderClaim('/expenses/new?mode=bulk')

    await userEvent.type(await screen.findByLabelText('タイトル'), '出張精算')
    await userEvent.click(screen.getByRole('button', { name: '登録方法の選択に戻る' }))
    await userEvent.click(await screen.findByRole('button', { name: '編集をやめる' }))

    expect(await screen.findByRole('button', { name: '個別に登録する' })).toBeInTheDocument()
    expect(screen.getByTestId('search')).toHaveTextContent(/^$/)
    expect(screen.queryByText('保存していない変更があります')).not.toBeInTheDocument()
  })

  it('ExpenseClaimNewPage: a query-only step back without input does not show the dialog', async () => {
    renderClaim('/expenses/new?mode=bulk')

    await screen.findByLabelText('タイトル')
    await userEvent.click(screen.getByRole('button', { name: '登録方法の選択に戻る' }))

    expect(await screen.findByRole('button', { name: '個別に登録する' })).toBeInTheDocument()
    expect(screen.queryByText('保存していない変更があります')).not.toBeInTheDocument()
  })

  it('ExpenseCategoryEditPage: create navigates to the list without the confirm dialog', async () => {
    vi.spyOn(expenseCategoriesApi, 'fetchExpenseCategories').mockResolvedValue([transport])
    vi.spyOn(expenseCategoriesApi, 'createExpenseCategory').mockResolvedValue({ ...transport, id: 2 })
    renderWithGuard(
      withQuery(
        <Routes>
          <Route path="/admin/expense-categories/:id" element={<ExpenseCategoryEditPage />} />
          <Route path="/admin/expense-categories" element={<p>経費区分一覧ページ</p>} />
        </Routes>,
      ),
      { initialEntries: ['/admin/expense-categories/new'] },
    )

    await userEvent.type(await screen.findByLabelText('コード'), 'supplies')
    await userEvent.type(screen.getByLabelText('名称'), '消耗品費')
    await userEvent.click(screen.getByRole('button', { name: '作成' }))

    expect(await screen.findByText('経費区分一覧ページ')).toBeInTheDocument()
    expect(screen.queryByText('保存していない変更があります')).not.toBeInTheDocument()
  })

  it('ExpenseEntryPresetEditPage: save navigates to the list without the confirm dialog', async () => {
    vi.spyOn(expenseEntryPresetsApi, 'fetchExpenseEntryPreset').mockResolvedValue(preset)
    vi.spyOn(expenseCategoriesApi, 'fetchExpenseCategories').mockResolvedValue([transport])
    vi.spyOn(expenseEntryPresetsApi, 'updateExpenseEntryPreset').mockResolvedValue(preset)
    renderWithGuard(
      withQuery(
        <Routes>
          <Route path="/expenses/presets/:id" element={<ExpenseEntryPresetEditPage />} />
          <Route path="/expenses/presets" element={<p>入力プリセット一覧ページ</p>} />
        </Routes>,
      ),
      { initialEntries: ['/expenses/presets/1'] },
    )

    const nameInput = await screen.findByLabelText('名称')
    await waitFor(() => expect(nameInput).toHaveValue('自宅⇔会社'))
    await userEvent.type(nameInput, 'A')
    await userEvent.click(screen.getByRole('button', { name: '保存' }))

    expect(await screen.findByText('入力プリセット一覧ページ')).toBeInTheDocument()
    expect(screen.queryByText('保存していない変更があります')).not.toBeInTheDocument()
  })
})
