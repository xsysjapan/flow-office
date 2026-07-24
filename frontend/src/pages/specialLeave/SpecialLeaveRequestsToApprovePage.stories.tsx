import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { SpecialLeaveRequest, User } from '../../api/types'
import { SpecialLeaveRequestsToApprovePage } from './SpecialLeaveRequestsToApprovePage'

const applicant: User = {
  id: 'user-1',
  name: '申請者太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const requests: SpecialLeaveRequest[] = [
  {
    id: 'request-1',
    user_id: 'user-1',
    user: applicant,
    special_leave_type_id: 1,
    special_leave_type_name: '誕生日休暇',
    status: 'submitted',
    leave_type: 'full',
    target_date: '2026-08-10',
    hours: null,
    requested_days: 1,
    reason: '誕生日のため',
    submitted_at: '2026-08-01T00:00:00+09:00',
    approved_at: null,
    returned_at: null,
    cancelled_at: null,
  },
  {
    id: 'request-2',
    user_id: 'user-1',
    user: applicant,
    special_leave_type_id: 2,
    special_leave_type_name: 'リフレッシュ休暇',
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

function withSeeded(data: SpecialLeaveRequest[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['special-leave', 'requests', 'to-approve'], data)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <SpecialLeaveRequestsToApprovePage />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/SpecialLeave/SpecialLeaveRequestsToApprovePage',
  component: SpecialLeaveRequestsToApprovePage,
} satisfies Meta<typeof SpecialLeaveRequestsToApprovePage>

export default meta
type Story = StoryObj<typeof meta>

export const WithRequests: Story = {
  render: withSeeded(requests),
}

export const Empty: Story = {
  render: withSeeded([]),
}
