import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import type { WorkCalendar, WorkCalendarDay, WorkCalendarYear } from '../../api/types'
import { WorkCalendarDaysPage } from './WorkCalendarDaysPage'

const calendar: WorkCalendar = {
  id: 'calendar-1',
  name: '本社カレンダー',
  week_starts_on: 0,
  fiscal_year_start_month: 4,
  fiscal_year_start_day: 1,
  holiday_calendar_source_id: null,
  is_default: true,
  status: 'active',
  weekday_holiday_pattern: {
    '1': 'working',
    '2': 'working',
    '3': 'working',
    '4': 'working',
    '5': 'working',
    '6': 'company_holiday',
    '7': 'legal_holiday',
  },
}

const year: WorkCalendarYear = {
  id: 'year-1',
  company_calendar_id: 'calendar-1',
  fiscal_year: 2026,
  starts_on: '2026-04-01',
  ends_on: '2026-05-31',
  status: 'draft',
  generated_from: 'manual',
  published_at: null,
  published_by_user_id: null,
}

/** 2026年4月・5月分の代表的なサンプル日(土日はOFF、5/4〜5/6は祝日)。 */
function buildSampleDays(): WorkCalendarDay[] {
  const days: WorkCalendarDay[] = []
  let id = 1
  for (const month of [4, 5]) {
    const daysInMonth = new Date(2026, month, 0).getDate()
    for (let d = 1; d <= daysInMonth; d += 1) {
      const date = `2026-${String(month).padStart(2, '0')}-${String(d).padStart(2, '0')}`
      const weekday = new Date(`${date}T00:00:00`).getDay()
      const isWeekend = weekday === 0 || weekday === 6
      const isGoldenWeekHoliday = month === 5 && d >= 3 && d <= 6

      days.push({
        id: id++,
        date,
        day_type: isGoldenWeekHoliday ? 'public_holiday' : isWeekend ? 'holiday' : 'weekday',
        is_working_day: !isWeekend && !isGoldenWeekHoliday,
        is_legal_holiday: weekday === 0,
        is_company_holiday: weekday === 6,
        is_public_holiday: isGoldenWeekHoliday,
        public_holiday_name: isGoldenWeekHoliday ? 'ゴールデンウィーク' : null,
        schedule_state: isWeekend || isGoldenWeekHoliday ? 'OFF' : 'WORK',
        note: null,
      })
    }
  }
  return days
}

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['work-calendars'], [calendar])
  queryClient.setQueryData(['work-calendar-years', calendar.id], [year])
  queryClient.setQueryData(['work-calendar-year-days', year.id], buildSampleDays())

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/admin/work-calendars/calendar-1/years/year-1/days']}>
          <Routes>
            <Route path="/admin/work-calendars/:calendarId/years/:yearId/days" element={<WorkCalendarDaysPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/WorkCalendar/WorkCalendarDaysPage',
  component: WorkCalendarDaysPage,
} satisfies Meta<typeof WorkCalendarDaysPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(),
}
