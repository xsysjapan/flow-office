import { useState } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { WorkCalendar } from '../../api/types'
import { CompanyCalendarSettingsModal } from './CompanyCalendarSettingsModal'

const calendar: WorkCalendar = {
  id: 'calendar-1',
  name: '本社カレンダー',
  week_starts_on: 1,
  fiscal_year_start_month: 4,
  fiscal_year_start_day: 1,
  holiday_calendar_source_id: null,
  is_default: true,
  status: 'active',
}

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })

  return function Decorator() {
    const [open, setOpen] = useState(true)
    return (
      <QueryClientProvider client={queryClient}>
        <CompanyCalendarSettingsModal companyCalendar={calendar} open={open} onOpenChange={setOpen} />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Components/CompanyCalendarSettingsModal',
  component: CompanyCalendarSettingsModal,
} satisfies Meta<typeof CompanyCalendarSettingsModal>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: { companyCalendar: calendar, open: true, onOpenChange: () => {} },
  render: withSeeded(),
}
