import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as expenseClaimsApi from '../../api/expenseClaims'
import type { ExpenseClaim, Paginated } from '../../api/types'
import { ExpenseClaimsToApprovePage } from './ExpenseClaimsToApprovePage'

function paginated(data: ExpenseClaim[]): Paginated<ExpenseClaim> {
  return { data, meta: { current_page: 1, last_page: 1, total: data.length }, links: { next: null, prev: null } }
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <ExpenseClaimsToApprovePage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('ExpenseClaimsToApprovePage', () => {
  it('shows an empty state when there is nothing to approve', async () => {
    vi.spyOn(expenseClaimsApi, 'fetchExpenseClaimsToApprove').mockResolvedValue(paginated([]))

    renderPage()

    expect(await screen.findByText('承認待ちの経費精算はありません。')).toBeInTheDocument()
  })

  it('lists claims awaiting approval with the applicant name and status', async () => {
    const claim: ExpenseClaim = {
      id: 'expense-claim-1',
      employee_id: 'employee-1',
      employee: { id: 'employee-1', name: '申請者太郎', email: 'taro@example.com', department: null, job_title: null, employment_status: 'active', last_login_at: null },
      period_from: '2026-07-01',
      period_to: '2026-07-31',
      status: 'in_review',
      approver_user_id: 'employee-2',
      total_amount: 5400,
      submitted_at: '2026-07-25T00:00:00+09:00',
      approved_at: null,
      items: [
        { id: 'item-1', category_id: 1, usage_date: '2026-07-10', description: '客先訪問', amount: 5400, project_id: null, evidence_type: 'receipt_required', fact_reference_type: null, fact_reference_id: null, commuting_deduction_amount: null },
      ],
    }
    vi.spyOn(expenseClaimsApi, 'fetchExpenseClaimsToApprove').mockResolvedValue(paginated([claim]))

    renderPage()

    expect(await screen.findByRole('link', { name: '2026-07-01 〜 2026-07-31' })).toHaveAttribute(
      'href',
      '/expenses/expense-claim-1',
    )
    expect(screen.getByText('申請者太郎')).toBeInTheDocument()
    expect(screen.getByText('申請中')).toBeInTheDocument()
    expect(screen.getByText('5,400円')).toBeInTheDocument()
  })

  it('shows a dash for the period when it has not been calculated yet', async () => {
    const claim: ExpenseClaim = {
      id: 'expense-claim-2',
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
    vi.spyOn(expenseClaimsApi, 'fetchExpenseClaimsToApprove').mockResolvedValue(paginated([claim]))

    renderPage()

    expect(await screen.findByRole('link', { name: '-' })).toHaveAttribute('href', '/expenses/expense-claim-2')
  })

  it('shows an error message when the fetch fails', async () => {
    vi.spyOn(expenseClaimsApi, 'fetchExpenseClaimsToApprove').mockRejectedValue(new Error('failure'))

    renderPage()

    expect(await screen.findByText('failure')).toBeInTheDocument()
  })
})
