import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import type {
  AttendanceDay,
  AttendanceMonth,
  AttendanceMonthlyCalculationTotals,
  Paginated,
  User,
  UserWorkStyleMonthlyAssignment,
} from '../../api/types'
import { AuthContext, type AuthContextValue } from '../../auth/AuthContext'
import { AttendanceMonthDetailPage } from './AttendanceMonthDetailPage'

const yearMonth = '2026-07'

const currentUser: User = {
  id: 1,
  name: '本人太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  hire_date: '2026-01-15',
  last_login_at: null,
}

const authValue: AuthContextValue = {
  user: currentUser,
  status: 'authenticated',
  login: fn(),
  completeLogin: fn(),
  applySession: fn(),
  logout: fn(),
}

const workStyleAssignments: UserWorkStyleMonthlyAssignment[] = ['2026-05', '2026-06', '2026-07', '2026-08'].map((ym, i) => ({
  id: i + 1,
  user_id: 1,
  year_month: ym,
  work_style_id: 1,
  assigned_by_user_id: 1,
}))

const emptyUsers: Paginated<User> = {
  data: [],
  meta: { current_page: 1, last_page: 1, total: 0 },
  links: { next: null, prev: null },
}

const month: AttendanceMonth = {
  id: 1,
  user_id: 1,
  year_month: yearMonth,
  status: 'not_submitted',
  submitted_at: null,
  approved_at: null,
  returned_at: null,
  closed_at: null,
  snapshot: null,
  legal_holiday_warnings: [],
}

const monthlyCalculationTotals: AttendanceMonthlyCalculationTotals = {
  work_minutes: 2820,
  payroll_work_minutes: 2820,
  prescribed_work_minutes: 2400,
  statutory_within_overtime_minutes: 60,
  statutory_excess_overtime_minutes: 360,
  statutory_excess_overtime_within_60h_minutes: 360,
  statutory_excess_overtime_over_60h_minutes: 0,
  late_night_work_minutes: 0,
  late_night_prescribed_work_minutes: 0,
  late_night_statutory_within_overtime_minutes: 0,
  late_night_statutory_excess_overtime_minutes: 0,
  legal_holiday_work_minutes: 0,
  prescribed_holiday_work_minutes: 0,
  late_night_legal_holiday_work_minutes: 0,
}

const days: AttendanceDay[] = [
  {
    id: 1,
    user_id: 1,
    work_date: '2026-07-01',
    status: 'clocked_out',
    actual_start_at: '2026-07-01T09:00:00+09:00',
    actual_end_at: '2026-07-01T18:00:00+09:00',
    work_type: null,
    note: null,
    is_locked: false,
    breaks: [],
    calculation: null,
  },
]

function withSeeded(monthData: { days: AttendanceDay[]; month: AttendanceMonth | null }) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['attendance', 'month', yearMonth], {
    ...monthData,
    flex_settlement_summary: null,
    monthly_calculation_totals: monthlyCalculationTotals,
  })
  queryClient.setQueryData(['users', ''], emptyUsers)
  queryClient.setQueryData(['user-work-style-monthly-assignments', currentUser.id], workStyleAssignments)

  return function Decorator() {
    return (
      <AuthContext.Provider value={authValue}>
        <QueryClientProvider client={queryClient}>
          <MemoryRouter initialEntries={[`/attendance/months/${yearMonth}`]}>
            <Routes>
              <Route path="/attendance/months/:yearMonth" element={<AttendanceMonthDetailPage />} />
            </Routes>
          </MemoryRouter>
        </QueryClientProvider>
      </AuthContext.Provider>
    )
  }
}

const meta = {
  title: 'Pages/Attendance/AttendanceMonthDetailPage',
  component: AttendanceMonthDetailPage,
} satisfies Meta<typeof AttendanceMonthDetailPage>

export default meta
type Story = StoryObj<typeof meta>

export const NotSubmitted: Story = {
  render: withSeeded({ days, month }),
}

export const Approved: Story = {
  render: withSeeded({ days, month: { ...month, status: 'approved' } }),
}
