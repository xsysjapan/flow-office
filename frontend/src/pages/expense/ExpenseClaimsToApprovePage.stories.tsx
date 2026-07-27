import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { ExpenseClaim, Paginated } from '../../api/types'
import { ExpenseClaimsToApprovePage } from './ExpenseClaimsToApprovePage'

function paginated(data: ExpenseClaim[]): Paginated<ExpenseClaim> {
  return { data, meta: { current_page: 1, last_page: 1, total: data.length }, links: { next: null, prev: null } }
}

const sample: ExpenseClaim[] = [
  {
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
  },
]

function withSeededList(data: ExpenseClaim[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['expense-claims', 'to-approve'], paginated(data))

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <ExpenseClaimsToApprovePage />
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/Expense/ExpenseClaimsToApprovePage',
  component: ExpenseClaimsToApprovePage,
} satisfies Meta<typeof ExpenseClaimsToApprovePage>

export default meta
type Story = StoryObj<typeof meta>

export const WithApprovals: Story = {
  render: withSeededList(sample),
}

export const Empty: Story = {
  render: withSeededList([]),
}
