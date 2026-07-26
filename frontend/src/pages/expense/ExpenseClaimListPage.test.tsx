import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as expenseClaimsApi from '../../api/expenseClaims'
import type { ExpenseClaim } from '../../api/types'
import { ExpenseClaimListPage } from './ExpenseClaimListPage'

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
  it('shows an empty state when there are no claims', async () => {
    vi.spyOn(expenseClaimsApi, 'fetchMyExpenseClaims').mockResolvedValue([])

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
        { id: 'item-1', category_id: 1, usage_date: '2026-06-05', origin: null, destination: null, transport_type: '電車', amount: 800, destination_name: null, purpose: '来客対応', project_id: null, evidence_type: 'receipt_required', fact_reference_type: null, fact_reference_id: null, commuting_deduction_amount: null },
      ],
    }
    vi.spyOn(expenseClaimsApi, 'fetchMyExpenseClaims').mockResolvedValue([claim])

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
    vi.spyOn(expenseClaimsApi, 'fetchMyExpenseClaims').mockResolvedValue([claim])

    renderPage()

    await screen.findByRole('link', { name: '2026-07-01 〜 2026-07-31' })
    expect(screen.getByText('下書き')).toBeInTheDocument()
    expect(screen.getByText('-')).toBeInTheDocument()
  })

  it('shows the new-claim link', async () => {
    vi.spyOn(expenseClaimsApi, 'fetchMyExpenseClaims').mockResolvedValue([])

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
