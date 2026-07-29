import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as attachmentsApi from '../../api/attachments'
import * as attendanceApi from '../../api/attendance'
import * as expenseClaimsApi from '../../api/expenseClaims'
import type { AttendanceDay, ExpenseClaim, ExpenseClaimHistoryEntry, User } from '../../api/types'
import { ExpenseClaimDetailPage } from './ExpenseClaimDetailPage'

const applicant: User = {
  id: 'applicant-1',
  name: '申請者太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const approver: User = {
  id: 'approver-1',
  name: '承認者花子',
  email: 'hanako@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

let currentUser: User = applicant

vi.mock('../../auth/useAuth', () => ({
  useAuth: () => ({ user: currentUser }),
}))

const claimItem = {
  id: 'item-1',
  claim_id: 'claim-1',
  category_id: 1,
  category: { id: 1, code: 'transport', name: '交通費', evidence_type_default: 'fact_reference_available' as const },
  usage_date: '2026-07-04',
  description: '自宅 → 本社(電車)',
  amount: 420,
  project_id: null,
  evidence_type: 'fact_reference_available' as const,
  fact_reference_type: 'attendance_day' as const,
  fact_reference_id: 'attendance-day-1',
  commuting_deduction_amount: null,
}

const inReviewClaim: ExpenseClaim = {
  id: 'claim-1',
  employee_id: 'applicant-1',
  employee: applicant,
  period_from: '2026-07-01',
  period_to: '2026-07-31',
  status: 'in_review',
  approver_user_id: 'approver-1',
  approver,
  total_amount: 420,
  submitted_at: '2026-07-05T00:00:00+09:00',
  approved_at: null,
  items: [claimItem],
}

const historyEntry: ExpenseClaimHistoryEntry = {
  id: 1,
  action: 'submitted',
  actor_user_id: 'applicant-1',
  comment: null,
  occurred_at: '2026-07-05T00:00:00+09:00',
}

const attendanceDay: AttendanceDay = {
  id: 'attendance-day-1',
  user_id: 'applicant-1',
  work_date: '2026-07-04',
  status: 'clocked_out',
  actual_start_at: '2026-07-04T09:00:00+09:00',
  actual_end_at: '2026-07-04T18:00:00+09:00',
  work_type: null,
  work_location_type: 'client_site',
  note: null,
  is_locked: false,
  breaks: [],
  calculation: null,
}

function renderPage(claim: ExpenseClaim) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(expenseClaimsApi, 'fetchExpenseClaim').mockResolvedValue(claim)
  vi.spyOn(expenseClaimsApi, 'fetchExpenseClaimHistory').mockResolvedValue([historyEntry])
  vi.spyOn(attachmentsApi, 'fetchAttachments').mockResolvedValue([])
  vi.spyOn(attendanceApi, 'fetchWeek').mockResolvedValue([attendanceDay])

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[`/expenses/${claim.id}`]}>
        <Routes>
          <Route path="/expenses/:id" element={<ExpenseClaimDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('ExpenseClaimDetailPage', () => {
  beforeEach(() => {
    currentUser = applicant
  })

  it('shows approve and return actions for the approver on an in_review claim', async () => {
    currentUser = approver
    renderPage(inReviewClaim)

    expect(await screen.findByRole('button', { name: '承認する' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '差戻す' })).toBeInTheDocument()
  })

  it('shows the reconciliation reference for the applicant approver to compare', async () => {
    currentUser = approver
    renderPage(inReviewClaim)

    expect(await screen.findByText('2026-07-04 客先と記録あり')).toBeInTheDocument()
  })

  it('approves the claim when the approver clicks approve', async () => {
    currentUser = approver
    vi.spyOn(expenseClaimsApi, 'approveExpenseClaim').mockResolvedValue({ ...inReviewClaim, status: 'approved' })

    renderPage(inReviewClaim)
    await userEvent.click(await screen.findByRole('button', { name: '承認する' }))

    await waitFor(() => expect(expenseClaimsApi.approveExpenseClaim).toHaveBeenCalledWith('claim-1'))
  })

  it('returns the claim with a comment when the approver clicks return', async () => {
    currentUser = approver
    vi.spyOn(expenseClaimsApi, 'returnExpenseClaim').mockResolvedValue({ ...inReviewClaim, status: 'returned' })

    renderPage(inReviewClaim)
    await userEvent.type(await screen.findByPlaceholderText('差戻しコメント'), '領収書を確認してください')
    await userEvent.click(screen.getByRole('button', { name: '差戻す' }))

    await waitFor(() =>
      expect(expenseClaimsApi.returnExpenseClaim).toHaveBeenCalledWith('claim-1', '領収書を確認してください'),
    )
  })

  it('shows cancel action for the applicant', async () => {
    renderPage(inReviewClaim)

    expect(await screen.findByRole('button', { name: '取り消す' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '承認する' })).not.toBeInTheDocument()
  })

  it('shows the event history', async () => {
    renderPage(inReviewClaim)

    expect(await screen.findByText('提出')).toBeInTheDocument()
  })

  it('shows a placeholder title when the period has not been calculated yet', async () => {
    renderPage({ ...inReviewClaim, period_from: null, period_to: null })

    expect(await screen.findByText('経費精算(対象期間未確定)')).toBeInTheDocument()
  })

  it('shows the claim title instead of the generic label when one has been set', async () => {
    renderPage({ ...inReviewClaim, title: '大阪出張分' })

    expect(await screen.findByText(`大阪出張分(${inReviewClaim.period_from} 〜 ${inReviewClaim.period_to})`)).toBeInTheDocument()
  })
})
