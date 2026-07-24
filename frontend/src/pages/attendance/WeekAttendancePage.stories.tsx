import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { AttendanceDay } from '../../api/types'
import { addDays, formatDate, mondayOf } from '../../utils/weekDates'
import { WeekAttendancePage } from './WeekAttendancePage'

const weekStart = formatDate(mondayOf(new Date()))

function buildDay(offset: number, overrides: Partial<AttendanceDay>): AttendanceDay {
  const workDate = addDays(weekStart, offset)
  return {
    id: `day-${offset + 1}`,
    user_id: '11111111-1111-1111-1111-111111111111',
    work_date: workDate,
    status: 'clocked_out',
    actual_start_at: `${workDate}T09:00:00+09:00`,
    actual_end_at: `${workDate}T18:00:00+09:00`,
    work_type: null,
    note: null,
    is_locked: false,
    breaks: [{ id: offset + 1, break_start_at: `${workDate}T12:00:00+09:00`, break_end_at: `${workDate}T12:45:00+09:00` }],
    calculation: {
      planned_work_minutes: 480,
      work_minutes: 495,
      prescribed_work_minutes: 480,
      statutory_within_overtime_minutes: 15,
      statutory_excess_overtime_minutes: 0,
      late_night_work_minutes: 0,
      late_night_prescribed_work_minutes: 0,
      late_night_statutory_within_overtime_minutes: 0,
      late_night_statutory_excess_overtime_minutes: 0,
      legal_holiday_work_minutes: 0,
      prescribed_holiday_work_minutes: 0,
      late_night_legal_holiday_work_minutes: 0,
      core_time_violation: false,
      is_manually_adjusted: false,
    },
    ...overrides,
  }
}

const weekDays: AttendanceDay[] = [
  buildDay(0, {}),
  buildDay(1, { status: 'working', actual_end_at: null, breaks: [] }),
]

function withSeeded(days: AttendanceDay[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['attendance', 'week', weekStart], days)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <WeekAttendancePage />
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/Attendance/WeekAttendancePage',
  component: WeekAttendancePage,
} satisfies Meta<typeof WeekAttendancePage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(weekDays),
}

export const AllMissing: Story = {
  render: withSeeded([]),
}
