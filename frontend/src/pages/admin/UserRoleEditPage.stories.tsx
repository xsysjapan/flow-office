import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import type { Role, User, WorkStyle } from '../../api/types'
import { UserRoleEditPage } from './UserRoleEditPage'

const targetUser: User = {
  id: 'user-1',
  name: '山田太郎',
  email: 'yamada@example.com',
  department: '総務部',
  job_title: '主任',
  employment_status: 'active',
  roles: ['employee', 'general_affairs_staff'],
  last_login_at: '2026-07-08T09:00:00+09:00',
}

const roles: Role[] = [
  { id: 1, code: 'employee', name: '一般社員' },
  { id: 2, code: 'backoffice_staff', name: 'バックオフィス担当者' },
  { id: 3, code: 'accounting_staff', name: '経理担当者' },
  { id: 4, code: 'general_affairs_staff', name: '総務担当者' },
  { id: 5, code: 'hr_staff', name: '人事担当者' },
  { id: 6, code: 'admin', name: 'システム管理者' },
]

const defaultWorkStyle: WorkStyle = {
  id: 1,
  code: 'standard',
  name: '通常勤務',
  work_time_system: 'fixed',
  prescribed_daily_minutes: 480,
  prescribed_weekly_minutes: 2400,
  default_start_time: '09:00',
  default_end_time: '18:00',
  default_break_minutes: 60,
  rounding_unit_minutes: null,
  default_break_start_time: '12:00',
  default_break_end_time: '13:00',
  auto_break_enabled: false,
  calendar_id: 1,
  is_shift_based: false,
  is_default: true,
  system_generated: true,
  legal_holiday_rule: 'weekly',
  four_week_period_start_date: null,
  max_consecutive_work_days: null,
  settlement_start_day: null,
  core_time_enabled: false,
  core_time_start: null,
  core_time_end: null,
  flexible_time_start: null,
  flexible_time_end: null,
  applied_employee_count: null,
  active_shift_pattern_count: null,
  configuration_warnings: [],
  updated_at: null,
}

function withSeeded(user: User) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['users', 'detail', user.id], user)
  queryClient.setQueryData(['roles'], roles)
  queryClient.setQueryData(['work-styles'], [defaultWorkStyle])
  queryClient.setQueryData(['user-work-style-monthly-assignments', user.id], [])

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={[`/admin/users/${user.id}`]}>
          <Routes>
            <Route path="/admin/users/:id" element={<UserRoleEditPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/Admin/UserRoleEditPage',
  component: UserRoleEditPage,
} satisfies Meta<typeof UserRoleEditPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(targetUser),
}

export const NoRoles: Story = {
  render: withSeeded({ ...targetUser, roles: [] }),
}
