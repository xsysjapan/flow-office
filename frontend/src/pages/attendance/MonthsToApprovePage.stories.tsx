import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import type { AttendanceMonth, User } from '../../api/types'
import { AuthContext, type AuthContextValue } from '../../auth/AuthContext'
import { MonthsToApprovePage } from './MonthsToApprovePage'

const approverUser: User = {
  id: 2,
  name: '承認者花子',
  email: 'hanako@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  roles: ['employee'],
  last_login_at: null,
}

const hrStaffUser: User = {
  ...approverUser,
  id: 3,
  name: '人事一郎',
  roles: ['hr_staff'],
}

const months: AttendanceMonth[] = [
  {
    id: 1,
    user_id: 1,
    year_month: '2026-07',
    status: 'submitted',
    approver: approverUser,
    submitted_at: '2026-07-05T00:00:00+09:00',
    approved_at: null,
    returned_at: null,
    closed_at: null,
    snapshot: null,
    legal_holiday_warnings: [],
  },
  {
    id: 2,
    user_id: 4,
    year_month: '2026-06',
    status: 'approved',
    approver: approverUser,
    submitted_at: '2026-07-01T00:00:00+09:00',
    approved_at: '2026-07-02T00:00:00+09:00',
    returned_at: null,
    closed_at: null,
    snapshot: null,
    legal_holiday_warnings: [],
  },
]

function withSeeded(data: AttendanceMonth[], viewer: User) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['attendance', 'months', 'to-approve'], data)

  const authValue: AuthContextValue = {
    user: viewer,
    status: 'authenticated',
    login: fn(),
    completeLogin: fn(),
    logout: fn(),
  }

  return function Decorator() {
    return (
      <AuthContext.Provider value={authValue}>
        <QueryClientProvider client={queryClient}>
          <MonthsToApprovePage />
        </QueryClientProvider>
      </AuthContext.Provider>
    )
  }
}

const meta = {
  title: 'Pages/Attendance/MonthsToApprovePage',
  component: MonthsToApprovePage,
} satisfies Meta<typeof MonthsToApprovePage>

export default meta
type Story = StoryObj<typeof meta>

export const AsApprover: Story = {
  render: withSeeded(months, approverUser),
}

export const AsHrStaff: Story = {
  render: withSeeded(months, hrStaffUser),
}

export const Empty: Story = {
  render: withSeeded([], approverUser),
}
