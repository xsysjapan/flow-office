import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { HolidayCalendarSource } from '../../api/types'
import { HolidayCalendarSourcesPage } from './HolidayCalendarSourcesPage'

const sources: HolidayCalendarSource[] = [
  {
    id: 'source-1',
    name: '内閣府祝日カレンダー',
    ics_url: 'https://example.com/holidays.ics',
    sync_status: 'synced',
    last_synced_at: '2026-08-11T00:00:00+09:00',
    last_error: null,
    disabled_at: null,
  },
  {
    id: 'source-2',
    name: '旧ソース(同期失敗)',
    ics_url: 'https://example.com/old-holidays.ics',
    sync_status: 'failed',
    last_synced_at: '2026-07-01T00:00:00+09:00',
    last_error: 'HTTP 404',
    disabled_at: null,
  },
]

function withSeeded(data: HolidayCalendarSource[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['holiday-calendar-sources'], data)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <HolidayCalendarSourcesPage />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/WorkCalendar/HolidayCalendarSourcesPage',
  component: HolidayCalendarSourcesPage,
} satisfies Meta<typeof HolidayCalendarSourcesPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(sources),
}

export const Empty: Story = {
  render: withSeeded([]),
}
