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
}

const paginatedUsers: Paginated<User> = {
  data: [],
  meta: { current_page: 1, last_page: 1, total: 0 },
  links: { next: null, prev: null },
}

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['work-styles'], [workStyle])
  queryClient.setQueryData(['work-calendars'], [calendar])
  queryClient.setQueryData(['users', ''], paginatedUsers)

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
