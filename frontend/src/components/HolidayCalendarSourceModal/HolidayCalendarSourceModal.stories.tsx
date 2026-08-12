import { useState } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { HolidayCalendarSource, WorkCalendar } from '../../api/types'
import { HolidayCalendarSourceModal } from './HolidayCalendarSourceModal'

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
  },
]

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['holiday-calendar-sources'], sources)

  return function Decorator() {
    const [open, setOpen] = useState(true)
    return (
      <QueryClientProvider client={queryClient}>
        <HolidayCalendarSourceModal companyCalendar={calendar} open={open} onOpenChange={setOpen} />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Components/HolidayCalendarSourceModal',
  component: HolidayCalendarSourceModal,
} satisfies Meta<typeof HolidayCalendarSourceModal>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: { companyCalendar: calendar, open: true, onOpenChange: () => {} },
  render: withSeeded(),
}
