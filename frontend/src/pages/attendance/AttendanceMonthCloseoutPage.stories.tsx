import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import type { AttendanceMonth, User } from '../../api/types'
import { AuthContext, type AuthContextValue } from '../../auth/AuthContext'
import { AttendanceMonthCloseoutPage } from './AttendanceMonthCloseoutPage'

const hrStaffUser: User = {
  id: 'hr-1',
  name: '人事一郎',
  email: 'ichiro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  roles: ['hr_staff'],
  last_login_at: null,
}

const months: AttendanceMonth[] = [
  {
    id: 'month-1',
    user_id: 'user-1',
    year_month: '2026-07',
    status: 'approved',
    approver: hrStaffUser,
    submitted_at: '2026-07-01T00:00:00+09:00',
    approved_at: '2026-07-02T00:00:00+09:00',
    returned_at: null,
    return_comment: null,
    closed_at: null,
    snapshot: null,
    legal_holiday_warnings: [],
  },
  {
    id: 'month-2',
    user_id: 'user-4',
    year_month: '2026-06',
    status: 'approved',
    approver: hrStaffUser,
    submitted_at: '2026-06-01T00:00:00+09:00',
    approved_at: '2026-06-02T00:00:00+09:00',
    returned_at: null,
    return_comment: null,
    closed_at: null,
    snapshot: null,
    legal_holiday_warnings: [],
  },
]

function withSeeded(data: AttendanceMonth[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['attendance', 'months', 'to-approve', 'approved', '', '', 1], {
    data,
    meta: { current_page: 1, last_page: 1, total: data.length },
    links: { next: null, prev: null },
  })

  const authValue: AuthContextValue = {
    user: hrStaffUser,
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
          <AttendanceMonthCloseoutPage />
        </QueryClientProvider>
      </AuthContext.Provider>
    )
  }
}

const meta = {
  title: 'Pages/Attendance/AttendanceMonthCloseoutPage',
  component: AttendanceMonthCloseoutPage,
} satisfies Meta<typeof AttendanceMonthCloseoutPage>

export default meta
type Story = StoryObj<typeof meta>

export const AsHrStaff: Story = {
  render: withSeeded(months),
}

export const Empty: Story = {
  render: withSeeded([]),
}
