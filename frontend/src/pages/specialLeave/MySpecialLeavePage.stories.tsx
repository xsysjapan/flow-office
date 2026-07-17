import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { Paginated, SpecialLeaveGrant, SpecialLeaveRequest, SpecialLeaveType, User } from '../../api/types'
import { MySpecialLeavePage } from './MySpecialLeavePage'

const types: SpecialLeaveType[] = [
  { id: 1, name: '誕生日休暇', is_active: true },
  { id: 2, name: 'リフレッシュ休暇', is_active: true },
]

const grants: SpecialLeaveGrant[] = [
  {
    id: 1,
    user_id: 1,
    special_leave_type_id: 1,
    special_leave_type_name: '誕生日休暇',
    granted_on: '2026-07-01',
    expires_on: '2026-12-31',
    granted_days: 3,
    used_days: 1,
    remaining_days: 2,
    grant_reason: '誕生月付与',
  },
  {
    id: 2,
    user_id: 1,
    special_leave_type_id: 2,
    special_leave_type_name: 'リフレッシュ休暇',
    granted_on: '2026-04-01',
    expires_on: null,
    granted_days: 5,
    used_days: 0,
    remaining_days: 5,
    grant_reason: null,
  },
]

const requests: SpecialLeaveRequest[] = [
  {
    id: 1,
    user_id: 1,
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
]

const emptyUsers: Paginated<User> = {
  data: [],
  meta: { current_page: 1, last_page: 1, total: 0 },
  links: { next: null, prev: null },
}

function withSeeded(typeData: SpecialLeaveType[], grantData: SpecialLeaveGrant[], requestData: SpecialLeaveRequest[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['special-leave', 'types'], typeData)
  queryClient.setQueryData(['special-leave', 'grants', 'mine'], grantData)
  queryClient.setQueryData(['special-leave', 'requests', 'mine'], requestData)
  queryClient.setQueryData(['users', ''], emptyUsers)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <MySpecialLeavePage />
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/SpecialLeave/MySpecialLeavePage',
  component: MySpecialLeavePage,
} satisfies Meta<typeof MySpecialLeavePage>

export default meta
type Story = StoryObj<typeof meta>

export const WithGrantsAndRequests: Story = {
  render: withSeeded(types, grants, requests),
}

export const Empty: Story = {
  render: withSeeded(types, [], []),
}
