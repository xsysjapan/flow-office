import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { Paginated, User, WorkStyle } from '../../api/types'
import { ShiftsPage } from './ShiftsPage'

const workStyle: WorkStyle = {
  id: 'work-style-1',
  code: 'standard',
  name: '標準勤務',
  work_time_system: '通常労働時間制',
  prescribed_daily_minutes: 480,
  prescribed_weekly_minutes: 2400,
  default_start_time: '09:00',
  default_end_time: '18:00',
  default_break_minutes: 60,
  rounding_unit_minutes: null,
  rounding_mode: null,
  default_break_start_time: '12:00',
  default_break_end_time: '13:00',
  auto_break_enabled: false,
  calendar_id: 'calendar-1',
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
  applied_employee_count: 45,
  active_shift_pattern_count: null,
  configuration_warnings: [],
  updated_at: '2026-07-01T09:00:00+09:00',
}

const paginatedUsers: Paginated<User> = {
  data: [],
  meta: { current_page: 1, last_page: 1, total: 0 },
  links: { next: null, prev: null },
}

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['work-styles'], [workStyle])
  queryClient.setQueryData(['users', ''], paginatedUsers)
  queryClient.setQueryData(['shift-patterns'], [])
  queryClient.setQueryData(['rotation-patterns'], [])

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <ShiftsPage />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/WorkCalendar/ShiftsPage',
  component: ShiftsPage,
} satisfies Meta<typeof ShiftsPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(),
}
