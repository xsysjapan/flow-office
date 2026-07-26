import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
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

const category: ExpenseCategory = {
  id: 1,
  code: 'transport',
  name: '交通費',
  description: null,
  evidence_type_default: 'fact_reference_available',
  receipt_required_threshold: null,
  approval_skip_threshold: null,
  is_active: true,
}

const draftClaim: ExpenseClaim = {
  id: 'claim-1',
  employee_id: 'applicant-1',
  period_from: '2026-07-01',
  period_to: '2026-07-31',
  status: 'draft',
  approver_user_id: null,
  total_amount: 0,
  submitted_at: null,
  approved_at: null,
  items: [],
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(expenseCategoriesApi, 'fetchExpenseCategories').mockResolvedValue([category])
  vi.spyOn(expenseRouteTemplatesApi, 'fetchExpenseRouteTemplates').mockResolvedValue([])
  vi.spyOn(attendanceApi, 'fetchWeek').mockResolvedValue([])
  vi.spyOn(usersApi, 'fetchUsers').mockResolvedValue({
    data: [],
    meta: { current_page: 1, last_page: 1, total: 0 },
    links: { next: null, prev: null },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/expenses/new']}>
        <Routes>
          <Route path="/expenses/new" element={<ExpenseClaimNewPage />} />
          <Route path="/expenses/:id" element={<p>経費精算詳細ページ</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('ExpenseClaimNewPage', () => {
  it('creates a draft claim for the given period and shows the item entry tools', async () => {
    vi.spyOn(expenseClaimsApi, 'createExpenseClaim').mockResolvedValue(draftClaim)
    vi.spyOn(expenseClaimsApi, 'fetchExpenseClaim').mockResolvedValue(draftClaim)

    renderPage()

    await userEvent.type(await screen.findByLabelText('対象期間(開始)'), '2026-07-01')
    await userEvent.type(screen.getByLabelText('対象期間(終了)'), '2026-07-31')
    await userEvent.click(screen.getByRole('button', { name: '作成して明細入力へ進む' }))

    await waitFor(() =>
      expect(expenseClaimsApi.createExpenseClaim).toHaveBeenCalledWith({
        period_from: '2026-07-01',
        period_to: '2026-07-31',
      }),
    )

    expect(await screen.findByRole('tab', { name: '表形式入力' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: '移動経路入力' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'テンプレートから生成' })).toBeInTheDocument()
  })

  it('saves entered rows via the bulk items endpoint', async () => {
    vi.spyOn(expenseClaimsApi, 'createExpenseClaim').mockResolvedValue(draftClaim)
    vi.spyOn(expenseClaimsApi, 'fetchExpenseClaim').mockResolvedValue(draftClaim)
    vi.spyOn(expenseClaimsApi, 'addExpenseItemsBulk').mockResolvedValue([])

    renderPage()

    await userEvent.type(await screen.findByLabelText('対象期間(開始)'), '2026-07-01')
    await userEvent.type(screen.getByLabelText('対象期間(終了)'), '2026-07-31')
    await userEvent.click(screen.getByRole('button', { name: '作成して明細入力へ進む' }))

    await userEvent.click(await screen.findByRole('button', { name: '行を追加' }))
    await userEvent.type(screen.getByLabelText('1行目の日付'), '2026-07-04')
    await userEvent.type(screen.getByLabelText('1行目の金額'), '420')

    await userEvent.click(screen.getByRole('button', { name: /明細を保存する/ }))

    await waitFor(() =>
      expect(expenseClaimsApi.addExpenseItemsBulk).toHaveBeenCalledWith('claim-1', [
        expect.objectContaining({ usage_date: '2026-07-04', amount: 420, category_id: 1 }),
      ]),
    )
  })
})
