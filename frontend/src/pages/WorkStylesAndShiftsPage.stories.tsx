import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { Paginated, User, WorkCalendar, WorkStyle } from '../api/types'
import { WorkStylesAndShiftsPage } from './WorkStylesAndShiftsPage'

const calendar: WorkCalendar = {
  id: 1,
  name: '2026年度カレンダー',
  fiscal_year: 2026,
  starts_on: '2026-04-01',
  ends_on: '2027-03-31',
  week_starts_on: 0,
  status: 'published',
}

const workStyle: WorkStyle = {
  id: 1,
  code: 'standard',
  name: '標準勤務',
  work_time_system: '通常労働時間制',
  prescribed_daily_minutes: 480,
  prescribed_weekly_minutes: 2400,
  default_start_time: '09:00',
  default_end_time: '18:00',
  default_break_minutes: 60,
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

function withSeeded(workStyles: WorkStyle[] = [workStyle]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['work-styles'], workStyles)
  queryClient.setQueryData(['work-calendars'], [calendar])
  queryClient.setQueryData(['users', ''], paginatedUsers)
  queryClient.setQueryData(['shift-patterns'], [])

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <WorkStylesAndShiftsPage />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/WorkStylesAndShiftsPage',
  component: WorkStylesAndShiftsPage,
} satisfies Meta<typeof WorkStylesAndShiftsPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(),
}

/** 指示書 12.1節: 会社のデフォルト働き方が未設定の間に表示するオンボーディング。 */
export const OnboardingNeeded: Story = {
  render: withSeeded([]),
}
