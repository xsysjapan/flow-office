import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { ExpenseClaim, Paginated } from '../../api/types'
import { ExpenseClaimListPage } from './ExpenseClaimListPage'

function paginated(data: ExpenseClaim[]): Paginated<ExpenseClaim> {
  return { data, meta: { current_page: 1, last_page: 1, total: data.length }, links: { next: null, prev: null } }
}

const sample: ExpenseClaim[] = [
  {
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
  },
  {
    id: 'expense-claim-2',
    employee_id: 'employee-1',
    period_from: '2026-07-01',
    period_to: '2026-07-31',
    status: 'in_review',
    approver_user_id: 'employee-2',
    approver: { id: 'employee-2', name: '承認太郎', email: 'shonin@example.com', department: null, job_title: null, employment_status: 'active', last_login_at: null },
    total_amount: 3200,
    submitted_at: '2026-07-25T00:00:00+09:00',
    approved_at: null,
    items: [],
  },
]

function withSeededList(data: ExpenseClaim[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['expense-claims', 'mine'], paginated(data))

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <ExpenseClaimListPage />
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/Expense/ExpenseClaimListPage',
  component: ExpenseClaimListPage,
} satisfies Meta<typeof ExpenseClaimListPage>

export default meta
type Story = StoryObj<typeof meta>

export const WithClaims: Story = {
  render: withSeededList(sample),
}

export const Empty: Story = {
  render: withSeededList([]),
}
