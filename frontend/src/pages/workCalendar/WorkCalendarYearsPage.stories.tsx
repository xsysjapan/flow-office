import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import type { WorkCalendar, WorkCalendarYear } from '../../api/types'
import { WorkCalendarYearsPage } from './WorkCalendarYearsPage'

const calendar: WorkCalendar = {
  id: 'calendar-1',
  name: '2026年度カレンダー',
  week_starts_on: 0,
  fiscal_year_start_month: 4,
  fiscal_year_start_day: 1,
  holiday_calendar_source_id: null,
  is_default: true,
  status: 'active',
}

const years: WorkCalendarYear[] = [
  {
    id: 'year-1',
    company_calendar_id: 'calendar-1',
    fiscal_year: 2026,
    starts_on: '2026-04-01',
    ends_on: '2027-03-31',
    status: 'published',
    generated_from: 'manual',
    published_at: '2026-03-01T00:00:00+09:00',
    published_by_user_id: 'user-1',
  },
  {
    id: 'year-2',
    company_calendar_id: 'calendar-1',
    fiscal_year: 2027,
    starts_on: '2027-04-01',
    ends_on: '2028-03-31',
    status: 'draft',
    generated_from: 'manual',
    published_at: null,
    published_by_user_id: null,
  },
]

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['work-calendars'], [calendar])
  queryClient.setQueryData(['work-calendar-years', 'calendar-1'], years)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/admin/work-calendars/calendar-1/years']}>
          <Routes>
            <Route path="/admin/work-calendars/:id/years" element={<WorkCalendarYearsPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/WorkCalendar/WorkCalendarYearsPage',
  component: WorkCalendarYearsPage,
} satisfies Meta<typeof WorkCalendarYearsPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(),
}
