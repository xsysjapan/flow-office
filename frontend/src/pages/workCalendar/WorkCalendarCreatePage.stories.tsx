import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import type { HolidayCalendarSource } from '../../api/types'
import { WorkCalendarCreatePage } from './WorkCalendarCreatePage'

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
  queryClient.setQueryData(['holiday-calendar-sources'], sources)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/admin/work-calendars/new']}>
          <Routes>
            <Route path="/admin/work-calendars/new" element={<WorkCalendarCreatePage />} />
            <Route path="/admin/work-calendars/:id" element={<p>カレンダー詳細ページ</p>} />
            <Route path="/admin/work-calendars" element={<p>カレンダー一覧ページ</p>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/WorkCalendar/WorkCalendarCreatePage',
  component: WorkCalendarCreatePage,
} satisfies Meta<typeof WorkCalendarCreatePage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(),
}
