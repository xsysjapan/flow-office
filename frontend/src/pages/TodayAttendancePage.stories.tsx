import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { AttendanceDay } from '../api/types'
import { TodayAttendancePage } from './TodayAttendancePage'

function withSeededToday(day: AttendanceDay) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { staleTime: Infinity, retry: false } },
  })
  queryClient.setQueryData(['attendance', 'today'], day)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <TodayAttendancePage />
      </QueryClientProvider>
    )
  }
}

const baseDay: AttendanceDay = {
  id: 1,
  user_id: 1,
  work_date: '2026-07-09',
  status: 'not_started',
  actual_start_at: null,
  actual_end_at: null,
  work_type: null,
  note: null,
  is_locked: false,
  breaks: [],
  calculation: null,
  planned_start_at: '2026-07-09T09:00:00+09:00',
  planned_end_at: '2026-07-09T18:00:00+09:00',
}

const meta = {
  title: 'Pages/TodayAttendancePage',
  component: TodayAttendancePage,
} satisfies Meta<typeof TodayAttendancePage>

export default meta
type Story = StoryObj<typeof meta>

export const NotStarted: Story = {
  render: withSeededToday(baseDay),
}

export const Working: Story = {
  render: withSeededToday({
    ...baseDay,
    status: 'working',
    actual_start_at: '2026-07-09T09:02:00+09:00',
  }),
}

export const OnBreak: Story = {
  render: withSeededToday({
    ...baseDay,
    status: 'on_break',
    actual_start_at: '2026-07-09T09:02:00+09:00',
    breaks: [{ id: 1, break_start_at: '2026-07-09T12:00:00+09:00', break_end_at: null }],
  }),
}

export const ClockedOut: Story = {
  render: withSeededToday({
    ...baseDay,
    status: 'clocked_out',
    actual_start_at: '2026-07-09T09:00:00+09:00',
    actual_end_at: '2026-07-09T23:00:00+09:00',
    breaks: [{ id: 1, break_start_at: '2026-07-09T12:00:00+09:00', break_end_at: '2026-07-09T13:00:00+09:00' }],
    calculation: {
      planned_work_minutes: 480,
      actual_work_minutes: 780,
      prescribed_work_minutes: 480,
      non_statutory_overtime_minutes: 0,
      statutory_overtime_minutes: 300,
      late_night_minutes: 60,
      legal_holiday_work_minutes: 0,
      company_holiday_work_minutes: 0,
      legal_holiday_late_night_minutes: 0,
    },
  }),
}
