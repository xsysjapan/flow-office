import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { Paginated, PaidLeaveGrant, PaidLeaveRequest, User } from '../../api/types'
import { MyPaidLeavePage } from './MyPaidLeavePage'

const grants: PaidLeaveGrant[] = [
  {
    id: 1,
    user_id: 1,
    granted_on: '2025-04-01',
    expires_on: '2027-03-31',
    granted_days: 10,
    used_days: 3,
    remaining_days: 7,
    grant_reason: '法定付与',
  },
  {
    id: 2,
    user_id: 1,
    granted_on: '2026-04-01',
    expires_on: '2028-03-31',
    granted_days: 11,
    used_days: 0,
    remaining_days: 11,
    grant_reason: null,
  },
]

const requests: PaidLeaveRequest[] = [
  {
    id: 1,
    user_id: 1,
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
    id: 2,
    user_id: 1,
    status: 'approved',
    leave_type: 'am_half',
    target_date: '2026-07-20',
    hours: null,
    requested_days: 0.5,
    reason: null,
    submitted_at: '2026-07-01T00:00:00+09:00',
    approved_at: '2026-07-02T00:00:00+09:00',
    returned_at: null,
    cancelled_at: null,
  },
]

const emptyUsers: Paginated<User> = {
  data: [],
  meta: { current_page: 1, last_page: 1, total: 0 },
  links: { next: null, prev: null },
}

function withSeeded(grantData: PaidLeaveGrant[], requestData: PaidLeaveRequest[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['paid-leave', 'grants', 'mine'], grantData)
  queryClient.setQueryData(['paid-leave', 'requests', 'mine'], requestData)
  queryClient.setQueryData(['users', ''], emptyUsers)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <MyPaidLeavePage />
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/PaidLeave/MyPaidLeavePage',
  component: MyPaidLeavePage,
} satisfies Meta<typeof MyPaidLeavePage>

export default meta
type Story = StoryObj<typeof meta>

export const WithGrantsAndRequests: Story = {
  render: withSeeded(grants, requests),
}

export const Empty: Story = {
  render: withSeeded([], []),
}
