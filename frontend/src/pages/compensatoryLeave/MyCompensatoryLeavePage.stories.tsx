import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { CompensatoryLeaveGrant, CompensatoryLeaveRequest, Paginated, User } from '../../api/types'
import { MyCompensatoryLeavePage } from './MyCompensatoryLeavePage'

const grants: CompensatoryLeaveGrant[] = [
  {
    id: 'grant-1',
    user_id: '11111111-1111-1111-1111-111111111111',
    source: 'attendance',
    attendance_day_id: 'day-1',
    work_date: '2026-07-05',
    status: 'confirmed',
    granted_days: 1,
    granted_minutes: null,
    used_days: 0,
    used_minutes: null,
    remaining_days: 1,
    remaining_minutes: null,
    confirmed_at: '2026-07-06T00:00:00+09:00',
    expires_on: '2026-12-31',
    grant_reason: null,
  },
  {
    id: 'grant-2',
    user_id: '11111111-1111-1111-1111-111111111111',
    source: 'attendance',
    attendance_day_id: 'day-2',
    work_date: '2026-07-20',
    status: 'confirmed',
    granted_days: 0.25,
    granted_minutes: 120,
    used_days: 0,
    used_minutes: 0,
    remaining_days: 0.25,
    remaining_minutes: 120,
    confirmed_at: '2026-07-21T00:00:00+09:00',
    expires_on: '2026-12-31',
    grant_reason: null,
  },
]

const requests: CompensatoryLeaveRequest[] = [
  {
    id: 'request-1',
    user_id: '11111111-1111-1111-1111-111111111111',
    status: 'submitted',
    leave_type: 'full',
    target_date: '2026-08-10',
    hours: null,
    requested_days: 1,
    requested_minutes: null,
    reason: '振替のため',
    submitted_at: '2026-08-01T00:00:00+09:00',
    approved_at: null,
    returned_at: null,
    cancelled_at: null,
  },
  {
    id: 'request-2',
    user_id: '11111111-1111-1111-1111-111111111111',
    status: 'approved',
    leave_type: 'hourly',
    target_date: '2026-07-25',
    hours: 2,
    requested_days: 0.25,
    requested_minutes: 120,
    reason: null,
    submitted_at: '2026-07-20T00:00:00+09:00',
    approved_at: '2026-07-21T00:00:00+09:00',
    returned_at: null,
    cancelled_at: null,
  },
]

const emptyUsers: Paginated<User> = {
  data: [],
  meta: { current_page: 1, last_page: 1, total: 0 },
  links: { next: null, prev: null },
}

function withSeeded(grantData: CompensatoryLeaveGrant[], requestData: CompensatoryLeaveRequest[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['compensatory-leave', 'grants', 'mine'], grantData)
  queryClient.setQueryData(['compensatory-leave', 'requests', 'mine'], requestData)
  queryClient.setQueryData(['users', 'search', '', 100], emptyUsers)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <MyCompensatoryLeavePage />
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/CompensatoryLeave/MyCompensatoryLeavePage',
  component: MyCompensatoryLeavePage,
} satisfies Meta<typeof MyCompensatoryLeavePage>

export default meta
type Story = StoryObj<typeof meta>

export const WithGrantsAndRequests: Story = {
  render: withSeeded(grants, requests),
}

export const Empty: Story = {
  render: withSeeded([], []),
}
