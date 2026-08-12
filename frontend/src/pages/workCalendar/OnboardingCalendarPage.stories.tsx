import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { WorkCalendar } from '../../api/types'
import { OnboardingCalendarPage } from './OnboardingCalendarPage'

const calendars: WorkCalendar[] = [
  {
    id: 'calendar-1',
    name: '2026年度カレンダー',
    week_starts_on: 0,
    fiscal_year_start_month: 4,
    fiscal_year_start_day: 1,
    holiday_calendar_source_id: null,
    is_default: true,
    status: 'active',
  },
]

function withSeeded(data: WorkCalendar[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['work-calendars'], data)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <OnboardingCalendarPage />
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/WorkCalendar/OnboardingCalendarPage',
  component: OnboardingCalendarPage,
} satisfies Meta<typeof OnboardingCalendarPage>

export default meta
type Story = StoryObj<typeof meta>

export const NeedsCompanyCalendar: Story = {
  render: withSeeded([]),
}

export const ReadyToGenerate: Story = {
  render: withSeeded(calendars),
}
