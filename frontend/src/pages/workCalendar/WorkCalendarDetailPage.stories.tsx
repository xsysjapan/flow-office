import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import type { HolidayCalendarSource, WorkCalendar } from '../../api/types'
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
}

const sources: HolidayCalendarSource[] = [
  {
    id: 'source-1',
    name: '内閣府祝日カレンダー',
    ics_url: 'https://example.com/holidays.ics',
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

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/admin/work-calendars/calendar-1']}>
          <Routes>
            <Route path="/admin/work-calendars/:id" element={<WorkCalendarDetailPage />} />
            <Route path="/admin/work-calendars/:id/years" element={<p>カレンダー年度一覧ページ</p>} />
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
