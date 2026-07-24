import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { PaidLeaveRequest, User } from '../../api/types'
import { PaidLeaveRequestsToApprovePage } from './PaidLeaveRequestsToApprovePage'

const applicant: User = {
  id: 'user-1',
  name: '申請者太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const requests: PaidLeaveRequest[] = [
  {
    id: 'request-1',
    user_id: 'user-1',
    user: applicant,
    status: 'submitted',
    leave_type: 'full',
    target_date: '2026-08-10',
    hours: null,
    requested_days: 1,
    reason: '私用のため',
    submitted_at: '2026-08-01T00:00:00+09:00',
    approved_at: null,
    returned_at: null,
    cancelled_at: null,
  },
  {
    id: 'request-2',
    user_id: 'user-1',
    user: applicant,
    status: 'submitted',
    leave_type: 'hourly',
    target_date: '2026-08-12',
    hours: 2,
    requested_days: 0.3,
    reason: null,
    submitted_at: '2026-08-02T00:00:00+09:00',
    approved_at: null,
    returned_at: null,
    cancelled_at: null,
  },
]

function withSeeded(data: PaidLeaveRequest[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['paid-leave', 'requests', 'to-approve'], data)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <PaidLeaveRequestsToApprovePage />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/PaidLeave/PaidLeaveRequestsToApprovePage',
  component: PaidLeaveRequestsToApprovePage,
} satisfies Meta<typeof PaidLeaveRequestsToApprovePage>

export default meta
type Story = StoryObj<typeof meta>

export const WithRequests: Story = {
  render: withSeeded(requests),
}

export const Empty: Story = {
  render: withSeeded([]),
}
