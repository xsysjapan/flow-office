import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import type { WorkCalendar } from '../../api/types'
import { WorkCalendarDaysPage } from './WorkCalendarDaysPage'

const calendar: WorkCalendar = {
  id: 'calendar-1',
  name: '2026年度カレンダー',
  fiscal_year: 2026,
  starts_on: '2026-04-01',
  ends_on: '2027-03-31',
  week_starts_on: 0,
  status: 'draft',
}

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['work-calendars'], [calendar])

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/admin/work-calendars/calendar-1/days']}>
          <Routes>
            <Route path="/admin/work-calendars/:id/days" element={<WorkCalendarDaysPage />} />
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
