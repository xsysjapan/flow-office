import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import type { HolidayCalendarSource, WorkCalendar, WorkCalendarYear } from '../../api/types'
import { WorkCalendarDetailPage } from './WorkCalendarDetailPage'

const calendar: WorkCalendar = {
  id: 'calendar-1',
  name: '本社カレンダー',
  week_starts_on: 1,
  fiscal_year_start_month: 4,
  fiscal_year_start_day: 1,
  holiday_calendar_source_id: 'source-1',
  is_default: true,
  status: 'active',
  weekday_holiday_pattern: { '1': 'working', '2': 'working', '3': 'working', '4': 'working', '5': 'working', '6': 'company_holiday', '7': 'legal_holiday' },
  allow_daily_holiday_override: true,
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

const sources: HolidayCalendarSource[] = [
  {
    id: 'source-1',
    name: '内閣府祝日カレンダー',
    source_kind: 'url',
    ics_url: 'https://example.com/holidays.ics',
    uploaded_ics_filename: null,
    sync_status: 'synced',
    last_synced_at: '2026-08-11T00:00:00+09:00',
    last_error: null,
    disabled_at: null,
    last_sync_summary: { added: 3, updated: 1, removed: 0, applied: 3, protected_conflicts: 1 },
  },
]

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['work-calendars'], [calendar])
  queryClient.setQueryData(['holiday-calendar-sources'], sources)
  queryClient.setQueryData(['work-calendar-years', 'calendar-1'], years)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/admin/work-calendars/calendar-1']}>
          <Routes>
            <Route path="/admin/work-calendars/:id" element={<WorkCalendarDetailPage />} />
            <Route path="/admin/work-calendars/:calendarId/years/:yearId/days" element={<p>日別編集ページ</p>} />
            <Route path="/admin/work-calendars" element={<p>カレンダー一覧ページ</p>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/WorkCalendarDetailPage',
  component: WorkCalendarDetailPage,
} satisfies Meta<typeof WorkCalendarDetailPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(),
}
