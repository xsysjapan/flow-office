import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import type { AttendanceDay, AttendancePunch, User } from '../api/types'
import { AuthContext, type AuthContextValue } from '../auth/AuthContext'
import { AttendanceDayPage } from './AttendanceDayPage'

const currentUser: User = {
  id: 1,
  name: '本人太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const authValue: AuthContextValue = {
  user: currentUser,
  status: 'authenticated',
  login: fn(),
  completeLogin: fn(),
  logout: fn(),
}

const date = '2026-07-06'

const recordedDay: AttendanceDay = {
  id: 1,
  user_id: 1,
  work_date: date,
  status: 'clocked_out',
  actual_start_at: `${date}T09:00:00+09:00`,
  actual_end_at: `${date}T18:00:00+09:00`,
  utc_offset_minutes: 540,
  work_type: null,
  note: null,
  is_locked: false,
  breaks: [{ id: 1, break_start_at: `${date}T12:00:00+09:00`, break_end_at: `${date}T12:45:00+09:00` }],
  calculation: {
    planned_work_minutes: 480,
    actual_work_minutes: 480,
    prescribed_work_minutes: 480,
    non_statutory_overtime_minutes: 0,
    statutory_overtime_minutes: 0,
    late_night_minutes: 0,
    legal_holiday_work_minutes: 0,
    company_holiday_work_minutes: 0,
    legal_holiday_late_night_minutes: 0,
    core_time_violation: false,
  },
}

const punch: AttendancePunch = {
  id: 10,
  user_id: 1,
  work_date: date,
  punch_type: 'clock_in',
  punched_at: `${date}T09:00:00+09:00`,
  source: 'web',
  note: null,
  status: 'active',
  correction_reason: null,
  corrected_by_user_id: null,
  corrected_at: null,
  superseded_by_punch_id: null,
  created_at: null,
}

function withSeeded(days: AttendanceDay[], punches: AttendancePunch[] = []) {
  const monday = '2026-07-06'
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['attendance', 'week', monday], days)
  queryClient.setQueryData(['attendance', 'punches', date, date], punches)

  return function Decorator() {
    return (
      <AuthContext.Provider value={authValue}>
        <QueryClientProvider client={queryClient}>
          <MemoryRouter initialEntries={[`/attendance/days/${date}`]}>
            <Routes>
              <Route path="/attendance/days/:date" element={<AttendanceDayPage />} />
            </Routes>
          </MemoryRouter>
        </QueryClientProvider>
      </AuthContext.Provider>
    )
  }
}

const meta = {
  title: 'Pages/AttendanceDayPage',
  component: AttendanceDayPage,
} satisfies Meta<typeof AttendanceDayPage>

export default meta
type Story = StoryObj<typeof meta>

export const Recorded: Story = {
  render: withSeeded([recordedDay], [punch]),
}

export const NoRecordYet: Story = {
  render: withSeeded([]),
}
