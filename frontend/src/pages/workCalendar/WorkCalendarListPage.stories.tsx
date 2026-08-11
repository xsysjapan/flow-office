import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { WorkCalendar } from '../../api/types'
import { WorkCalendarListPage } from './WorkCalendarListPage'

const calendars: WorkCalendar[] = [
  {
    id: 'calendar-1',
    name: '2026年度カレンダー',
    week_starts_on: 0,
    fiscal_year_start_month: 4,
    fiscal_year_start_day: 1,
    holiday_calendar_source_id: null,
  },
  {
    id: 'calendar-2',
    name: '関西拠点カレンダー',
    week_starts_on: 1,
    fiscal_year_start_month: 4,
    fiscal_year_start_day: 1,
    holiday_calendar_source_id: 'holiday-source-1',
  },
]

function withSeeded(data: WorkCalendar[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['work-calendars'], data)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/admin/work-calendars']}>
          <WorkCalendarListPage />
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/WorkCalendar/WorkCalendarListPage',
  component: WorkCalendarListPage,
} satisfies Meta<typeof WorkCalendarListPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(calendars),
}

export const Empty: Story = {
  render: withSeeded([]),
}
