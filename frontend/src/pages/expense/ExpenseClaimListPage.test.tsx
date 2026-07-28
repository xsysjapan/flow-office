import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import * as expenseClaimsApi from '../../api/expenseClaims'
import type { ExpenseClaim, Paginated } from '../../api/types'
import { ExpenseClaimListPage } from './ExpenseClaimListPage'

function paginated(data: ExpenseClaim[]): Paginated<ExpenseClaim> {
  return { data, meta: { current_page: 1, last_page: 1, total: data.length }, links: { next: null, prev: null } }
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <ExpenseClaimListPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('ExpenseClaimListPage', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('shows an empty state when there are no claims', async () => {
    vi.spyOn(expenseClaimsApi, 'fetchMyExpenseClaims').mockResolvedValue(paginated([]))

    renderPage()

    expect(await screen.findByText('経費精算はまだありません。')).toBeInTheDocument()
  })

  it('lists claims with their status, item count, total amount and approver', async () => {
    const claim: ExpenseClaim = {
      id: 'expense-claim-1',
      employee_id: 'employee-1',
      period_from: '2026-06-01',
      period_to: '2026-06-30',
      status: 'approved',
      approver_user_id: 'employee-2',
      approver: { id: 'employee-2', name: '承認太郎', email: 'shonin@example.com', department: null, job_title: null, employment_status: 'active', last_login_at: null },
      total_amount: 12800,
      submitted_at: '2026-07-01T00:00:00+09:00',
      approved_at: '2026-07-02T00:00:00+09:00',
      items: [
        { id: 'item-1', category_id: 1, usage_date: '2026-06-05', description: '来客対応', amount: 800, project_id: null, evidence_type: 'receipt_required', fact_reference_type: null, fact_reference_id: null, commuting_deduction_amount: null },
      ],
    }
    vi.spyOn(expenseClaimsApi, 'fetchMyExpenseClaims').mockResolvedValue(paginated([claim]))

    renderPage()

    expect(await screen.findByRole('link', { name: '2026-06-01 〜 2026-06-30' })).toHaveAttribute(
      'href',
      '/expenses/expense-claim-1',
    )
    expect(screen.getByText('1')).toBeInTheDocument()
    expect(screen.getByText('12,800円')).toBeInTheDocument()
    expect(screen.getByText('承認済み')).toBeInTheDocument()
    expect(screen.getByText('承認太郎')).toBeInTheDocument()
  })

  it('shows a dash for the approver when none is set', async () => {
    const claim: ExpenseClaim = {
      id: 'expense-claim-2',
      employee_id: 'employee-1',
      period_from: '2026-07-01',
      period_to: '2026-07-31',
      status: 'draft',
      approver_user_id: null,
      total_amount: 0,
      submitted_at: null,
      approved_at: null,
      items: [],
    }
    vi.spyOn(expenseClaimsApi, 'fetchMyExpenseClaims').mockResolvedValue(paginated([claim]))

    renderPage()

    await screen.findByRole('link', { name: '2026-07-01 〜 2026-07-31' })
    expect(screen.getByText('下書き')).toBeInTheDocument()
    expect(screen.getByText('-')).toBeInTheDocument()
  })

  it('shows a dash for the period when it has not been calculated yet (no items saved)', async () => {
    const claim: ExpenseClaim = {
      id: 'expense-claim-3',
      employee_id: 'employee-1',
      period_from: null,
      period_to: null,
      status: 'draft',
      approver_user_id: null,
      total_amount: 0,
      submitted_at: null,
      approved_at: null,
      items: [],
    }
    vi.spyOn(expenseClaimsApi, 'fetchMyExpenseClaims').mockResolvedValue(paginated([claim]))

    renderPage()

    expect(await screen.findByRole('link', { name: '-' })).toHaveAttribute('href', '/expenses/expense-claim-3')
  })

  it('lets a draft be edited or deleted, but not an approved claim', async () => {
    const draft: ExpenseClaim = {
      id: 'expense-claim-draft',
      employee_id: 'employee-1',
      period_from: null,
      period_to: null,
      status: 'draft',
      approver_user_id: null,
      total_amount: 0,
      submitted_at: null,
      approved_at: null,
      items: [],
    }
    const approved: ExpenseClaim = {
      id: 'expense-claim-approved',
      employee_id: 'employee-1',
      period_from: '2026-06-01',
      period_to: '2026-06-30',
      status: 'approved',
      approver_user_id: null,
      total_amount: 0,
      submitted_at: null,
      approved_at: null,
      items: [],
    }
    vi.spyOn(expenseClaimsApi, 'fetchMyExpenseClaims').mockResolvedValue(paginated([draft, approved]))

    renderPage()

    await screen.findByRole('link', { name: '-' })
    expect(screen.getAllByRole('link', { name: '編集を続ける' })).toHaveLength(1)
    expect(screen.getAllByRole('button', { name: '削除' })).toHaveLength(1)
  })

  it('deletes a draft when its 削除 button is clicked', async () => {
    const draft: ExpenseClaim = {
      id: 'expense-claim-draft',
      employee_id: 'employee-1',
      period_from: null,
      period_to: null,
      status: 'draft',
      approver_user_id: null,
      total_amount: 0,
      submitted_at: null,
      approved_at: null,
      items: [],
    }
    vi.spyOn(expenseClaimsApi, 'fetchMyExpenseClaims').mockResolvedValue(paginated([draft]))
    const deleteClaim = vi.spyOn(expenseClaimsApi, 'deleteExpenseClaim').mockResolvedValue(undefined)

    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '削除' }))
    expect(await screen.findByText('この下書きを削除しますか?')).toBeInTheDocument()
    expect(deleteClaim).not.toHaveBeenCalled()

    await userEvent.click(await screen.findByRole('button', { name: '削除する' }))

    await waitFor(() => expect(deleteClaim).toHaveBeenCalledWith('expense-claim-draft'))
  })

  it('does not delete when the confirmation dialog is cancelled', async () => {
    const draft: ExpenseClaim = {
      id: 'expense-claim-draft',
      employee_id: 'employee-1',
      period_from: null,
      period_to: null,
      status: 'draft',
      approver_user_id: null,
      total_amount: 0,
      submitted_at: null,
      approved_at: null,
      items: [],
    }
    vi.spyOn(expenseClaimsApi, 'fetchMyExpenseClaims').mockResolvedValue(paginated([draft]))
    const deleteClaim = vi.spyOn(expenseClaimsApi, 'deleteExpenseClaim').mockResolvedValue(undefined)

    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '削除' }))
    await userEvent.click(await screen.findByRole('button', { name: 'キャンセル' }))

    expect(deleteClaim).not.toHaveBeenCalled()
  })

  it('shows the new-claim link', async () => {
    vi.spyOn(expenseClaimsApi, 'fetchMyExpenseClaims').mockResolvedValue(paginated([]))

    renderPage()

    await screen.findByText('経費精算はまだありません。')
    expect(screen.getByRole('link', { name: '新規作成' })).toHaveAttribute('href', '/expenses/new')
  })

  it('shows loading state while fetching', () => {
    vi.spyOn(expenseClaimsApi, 'fetchMyExpenseClaims').mockReturnValue(new Promise(() => {}))

    renderPage()

    expect(document.body).toBeInTheDocument()
  })

  it('shows an error message when the fetch fails', async () => {
    vi.spyOn(expenseClaimsApi, 'fetchMyExpenseClaims').mockRejectedValue(new Error('failure'))

    renderPage()

    expect(await screen.findByText('failure')).toBeInTheDocument()
  })
})
