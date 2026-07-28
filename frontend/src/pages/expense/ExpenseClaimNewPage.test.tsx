import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import * as attendanceApi from '../../api/attendance'
import * as expenseCategoriesApi from '../../api/expenseCategories'
import * as expenseClaimsApi from '../../api/expenseClaims'
import * as expenseRouteTemplatesApi from '../../api/expenseRouteTemplates'
import * as usersApi from '../../api/users'
import type { ExpenseCategory, ExpenseClaim, User } from '../../api/types'
import { ExpenseClaimNewPage } from './ExpenseClaimNewPage'

const applicant: User = {
  id: 'applicant-1',
  name: '申請者太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

vi.mock('../../auth/useAuth', () => ({
  useAuth: () => ({ user: applicant }),
}))

const transportCategory: ExpenseCategory = {
  id: 1,
  code: 'transport',
  name: '交通費',
  description: null,
  evidence_type_default: 'fact_reference_available',
  entry_mode: 'batch',
  receipt_required_threshold: null,
  approval_skip_threshold: null,
  is_active: true,
}

const lodgingCategory: ExpenseCategory = {
  id: 2,
  code: 'lodging',
  name: '宿泊費',
  description: null,
  evidence_type_default: 'receipt_required',
  entry_mode: 'single',
  receipt_required_threshold: 0,
  approval_skip_threshold: null,
  is_active: true,
}

function draftClaim(overrides: Partial<ExpenseClaim> = {}): ExpenseClaim {
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
    items: [],
    ...overrides,
  }
}

function renderPage(
  categories: ExpenseCategory[] = [transportCategory, lodgingCategory],
  initialPath = '/expenses/new',
) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(expenseCategoriesApi, 'fetchExpenseCategories').mockResolvedValue(categories)
  vi.spyOn(expenseRouteTemplatesApi, 'fetchExpenseRouteTemplates').mockResolvedValue([])
  vi.spyOn(attendanceApi, 'fetchWeek').mockResolvedValue([])
  vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue({
    data: [],
    meta: { current_page: 1, last_page: 1, total: 0 },
    links: { next: null, prev: null },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialPath]}>
        <Routes>
          <Route path="/expenses/new" element={<ExpenseClaimNewPage />} />
          <Route path="/expenses/:id/edit" element={<ExpenseClaimNewPage />} />
          <Route path="/expenses/:id" element={<p>経費精算詳細ページ</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('ExpenseClaimNewPage', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('shows the category selection step without asking for a target period', async () => {
    renderPage()

    expect(await screen.findByRole('button', { name: '交通費' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '宿泊費' })).toBeInTheDocument()
    expect(screen.queryByLabelText('対象期間(開始)')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('対象期間(終了)')).not.toBeInTheDocument()
  })

  it('shows the item entry tools for a batch category without creating a draft claim yet', async () => {
    const createClaim = vi.spyOn(expenseClaimsApi, 'createExpenseClaim').mockResolvedValue(draftClaim())

    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '交通費' }))

    expect(await screen.findByRole('tab', { name: '表形式入力' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: '移動経路入力' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'テンプレートから生成' })).toBeInTheDocument()
    expect(createClaim).not.toHaveBeenCalled()
  })

  it('creates a draft claim with no body only when the first rows are actually saved (batch category)', async () => {
    vi.spyOn(expenseClaimsApi, 'createExpenseClaim').mockResolvedValue(draftClaim())
    vi.spyOn(expenseClaimsApi, 'fetchExpenseClaim').mockResolvedValue(draftClaim())
    vi.spyOn(expenseClaimsApi, 'addExpenseItemsBulk').mockResolvedValue([])

    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '交通費' }))
    expect(expenseClaimsApi.createExpenseClaim).not.toHaveBeenCalled()

    await userEvent.click(await screen.findByRole('button', { name: '行を追加' }))
    await userEvent.type(screen.getByLabelText('1行目の日付'), '2026-07-04')
    await userEvent.type(screen.getByLabelText('1行目の金額'), '420')

    await userEvent.click(screen.getByRole('button', { name: /明細を保存する/ }))

    await waitFor(() => expect(expenseClaimsApi.createExpenseClaim).toHaveBeenCalledWith())
    await waitFor(() =>
      expect(expenseClaimsApi.addExpenseItemsBulk).toHaveBeenCalledWith('claim-1', [
        expect.objectContaining({ usage_date: '2026-07-04', amount: 420, category_id: 1 }),
      ]),
    )
  })

  it('shows the single-item form for a single-entry category without creating a draft claim yet', async () => {
    const createClaim = vi.spyOn(expenseClaimsApi, 'createExpenseClaim').mockResolvedValue(draftClaim())

    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '宿泊費' }))

    expect(await screen.findByLabelText('利用日')).toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: '表形式入力' })).not.toBeInTheDocument()
    expect(createClaim).not.toHaveBeenCalled()
  })

  it('creates a draft claim only when the first single item is saved, then keeps reusing it', async () => {
    vi.spyOn(expenseClaimsApi, 'createExpenseClaim').mockResolvedValue(draftClaim())
    vi.spyOn(expenseClaimsApi, 'fetchExpenseClaim').mockResolvedValue(draftClaim())
    vi.spyOn(expenseClaimsApi, 'addExpenseItem').mockResolvedValue({
      id: 'item-1',
      category_id: 2,
      usage_date: '2026-07-10',
      description: 'ホテルABC',
      amount: 12000,
      project_id: null,
      evidence_type: 'receipt_required',
      fact_reference_type: null,
      fact_reference_id: null,
      commuting_deduction_amount: null,
    })

    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '宿泊費' }))
    expect(expenseClaimsApi.createExpenseClaim).not.toHaveBeenCalled()

    await userEvent.type(await screen.findByLabelText('利用日'), '2026-07-10')
    await userEvent.type(screen.getByLabelText('金額'), '12000')
    await userEvent.type(screen.getByLabelText('宿泊先名'), 'ホテルABC')
    await userEvent.click(screen.getByRole('button', { name: '明細を保存して続けて入力する' }))

    await waitFor(() => expect(expenseClaimsApi.createExpenseClaim).toHaveBeenCalledWith())
    await waitFor(() =>
      expect(expenseClaimsApi.addExpenseItem).toHaveBeenCalledWith('claim-1', {
        category_id: 2,
        usage_date: '2026-07-10',
        amount: 12000,
        description: 'ホテルABC',
      }),
    )

    expect(await screen.findByLabelText('宿泊先名')).toHaveValue('')
  })

  it('skips the category selection step when a ?category= shortcut param matches an active category', async () => {
    const createClaim = vi.spyOn(expenseClaimsApi, 'createExpenseClaim').mockResolvedValue(draftClaim())

    renderPage([transportCategory, lodgingCategory], '/expenses/new?category=lodging')

    expect(await screen.findByLabelText('利用日')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '宿泊費' })).not.toBeInTheDocument()
    expect(createClaim).not.toHaveBeenCalled()
  })

  it('ignores a ?category= shortcut param that does not match any active category', async () => {
    renderPage([transportCategory, lodgingCategory], '/expenses/new?category=unknown')

    expect(await screen.findByRole('button', { name: '交通費' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '宿泊費' })).toBeInTheDocument()
  })

  it('lets the user go back to category selection via 区分を変更する before anything is saved', async () => {
    renderPage([transportCategory, lodgingCategory], '/expenses/new?category=lodging')

    await screen.findByLabelText('利用日')
    await userEvent.click(screen.getByRole('button', { name: '区分を変更する' }))

    expect(await screen.findByRole('button', { name: '交通費' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '宿泊費' })).toBeInTheDocument()
  })

  it('does not create a second claim when returning to pick another category (UC-X013)', async () => {
    const createClaim = vi.spyOn(expenseClaimsApi, 'createExpenseClaim').mockResolvedValue(draftClaim())
    vi.spyOn(expenseClaimsApi, 'fetchExpenseClaim').mockResolvedValue(
      draftClaim({
        items: [
          {
            id: 'item-1',
            category_id: 2,
            usage_date: '2026-07-10',
            description: 'ホテルABC',
            amount: 12000,
            project_id: null,
            evidence_type: 'receipt_required',
            fact_reference_type: null,
            fact_reference_id: null,
            commuting_deduction_amount: null,
          },
        ],
      }),
    )
    vi.spyOn(expenseClaimsApi, 'addExpenseItem').mockResolvedValue({
      id: 'item-1',
      category_id: 2,
      usage_date: '2026-07-10',
      description: 'ホテルABC',
      amount: 12000,
      project_id: null,
      evidence_type: 'receipt_required',
      fact_reference_type: null,
      fact_reference_id: null,
      commuting_deduction_amount: null,
    })

    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '宿泊費' }))
    await userEvent.type(await screen.findByLabelText('利用日'), '2026-07-10')
    await userEvent.type(screen.getByLabelText('金額'), '12000')
    await userEvent.type(screen.getByLabelText('宿泊先名'), 'ホテルABC')
    await userEvent.click(screen.getByRole('button', { name: '明細を保存して続けて入力する' }))
    await waitFor(() => expect(createClaim).toHaveBeenCalled())
    const callCountAfterFirstSave = createClaim.mock.calls.length

    await userEvent.click(await screen.findByRole('button', { name: '別の区分の明細を追加する' }))
    expect(await screen.findByRole('button', { name: '交通費' })).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: '交通費' }))
    await screen.findByRole('tab', { name: '表形式入力' })
    expect(createClaim.mock.calls.length).toBe(callCountAfterFirstSave)
  })
})
