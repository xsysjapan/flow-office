import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { WorkCalendarDaysPage } from './WorkCalendarDaysPage'

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/admin/work-calendar-years/year-1/days']}>
          <Routes>
            <Route path="/admin/work-calendar-years/:yearId/days" element={<WorkCalendarDaysPage />} />
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
