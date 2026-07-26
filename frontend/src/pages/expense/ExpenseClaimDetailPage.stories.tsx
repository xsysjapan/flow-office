import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { fn } from 'storybook/test'
import type { AttendanceDay, ExpenseClaim, ExpenseClaimHistoryEntry, User } from '../../api/types'
import { AuthContext, type AuthContextValue } from '../../auth/AuthContext'
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

const inReviewClaim: ExpenseClaim = {
  id: 'claim-1',
  employee_id: 'applicant-1',
  employee: applicant,
  period_from: '2026-07-01',
  period_to: '2026-07-31',
  status: 'in_review',
  approver_user_id: 'approver-1',
  approver,
  total_amount: 1420,
  submitted_at: '2026-07-05T00:00:00+09:00',
  approved_at: null,
  items: [
    {
      id: 'item-1',
      claim_id: 'claim-1',
      category_id: 1,
      category: { id: 1, code: 'transport', name: '交通費', evidence_type_default: 'fact_reference_available' },
      usage_date: '2026-07-04',
      origin: '自宅',
      destination: '本社',
      transport_type: '電車',
      amount: 420,
      destination_name: null,
      purpose: null,
      project_id: null,
      evidence_type: 'fact_reference_available',
      fact_reference_type: 'attendance_day',
      fact_reference_id: 'attendance-day-1',
      commuting_deduction_amount: 150,
    },
    {
      id: 'item-2',
      claim_id: 'claim-1',
      category_id: 2,
      category: { id: 2, code: 'lodging', name: '宿泊費', evidence_type_default: 'receipt_required' },
      usage_date: '2026-07-10',
      origin: null,
      destination: null,
      transport_type: null,
      amount: 1000,
      destination_name: '取引先出張',
      purpose: '出張',
      project_id: null,
      evidence_type: 'receipt_required',
      fact_reference_type: null,
      fact_reference_id: null,
      commuting_deduction_amount: null,
    },
  ],
}

const sampleHistory: ExpenseClaimHistoryEntry[] = [
  { id: 1, action: 'drafted', actor_user_id: 'applicant-1', comment: null, occurred_at: '2026-07-02T00:00:00+09:00' },
  { id: 2, action: 'submitted', actor_user_id: 'applicant-1', comment: null, occurred_at: '2026-07-05T00:00:00+09:00' },
]

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

function withSeeded(claim: ExpenseClaim, viewer: User) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['expense-claims', claim.id], claim)
  queryClient.setQueryData(['expense-claims', claim.id, 'history'], sampleHistory)
  for (const item of claim.items) {
    queryClient.setQueryData(['attachments', 'expense_item', item.id], [])
  }
  queryClient.setQueryData(['attendance', 'week', '2026-06-29', claim.employee_id], [attendanceDay])
  queryClient.setQueryData(['attendance', 'week', '2026-07-06', claim.employee_id], [])

  const authValue: AuthContextValue = {
    user: viewer,
    status: 'authenticated',
    login: fn(),
    completeLogin: fn(),
    applySession: fn(),
    logout: fn(),
  }

  return function Decorator() {
    return (
      <AuthContext.Provider value={authValue}>
        <QueryClientProvider client={queryClient}>
          <MemoryRouter initialEntries={[`/expenses/${claim.id}`]}>
            <Routes>
              <Route path="/expenses/:id" element={<ExpenseClaimDetailPage />} />
            </Routes>
          </MemoryRouter>
        </QueryClientProvider>
      </AuthContext.Provider>
    )
  }
}

const meta = {
  title: 'Pages/Expense/ExpenseClaimDetailPage',
  component: ExpenseClaimDetailPage,
} satisfies Meta<typeof ExpenseClaimDetailPage>

export default meta
type Story = StoryObj<typeof meta>

export const AsApprover: Story = {
  render: withSeeded(inReviewClaim, approver),
}

export const AsApplicant: Story = {
  render: withSeeded(inReviewClaim, applicant),
}

export const Draft: Story = {
  render: withSeeded({ ...inReviewClaim, status: 'draft', submitted_at: null }, applicant),
}
