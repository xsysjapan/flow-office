import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { WorkCalendar } from '../../api/types'
import { WorkCalendarListPage } from './WorkCalendarListPage'

const calendars: WorkCalendar[] = [
  {
    id: 'calendar-1',
    name: '2026年度カレンダー',
    fiscal_year: 2026,
    starts_on: '2026-04-01',
    ends_on: '2027-03-31',
    week_starts_on: 0,
    status: 'published',
  },
  {
    id: 'calendar-2',
    name: '2027年度カレンダー(準備中)',
    fiscal_year: 2027,
    starts_on: '2027-04-01',
    ends_on: '2028-03-31',
    week_starts_on: 0,
    status: 'draft',
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
