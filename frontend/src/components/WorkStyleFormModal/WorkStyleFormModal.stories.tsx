import { useState } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { WorkCalendar, WorkStyle } from '../../api/types'
import { WorkStyleFormModal } from './WorkStyleFormModal'

const calendar: WorkCalendar = {
  id: 'calendar-1',
  name: '2026年度カレンダー',
  fiscal_year: 2026,
  starts_on: '2026-04-01',
  ends_on: '2027-03-31',
  week_starts_on: 0,
  status: 'published',
}

const workStyle: WorkStyle = {
  id: 'work-style-1',
  code: 'standard',
  name: '標準勤務',
  work_time_system: 'fixed',
  prescribed_daily_minutes: 480,
  prescribed_weekly_minutes: 2400,
  deemed_daily_minutes: null,
  default_start_time: '09:00',
  default_end_time: '18:00',
  default_break_minutes: 60,
  rounding_unit_minutes: null,
  rounding_mode: null,
  default_break_start_time: '12:00',
  default_break_end_time: '13:00',
  auto_break_enabled: false,
  calendar_id: 'calendar-1',
  is_shift_based: false,
  is_default: true,
  system_generated: true,
  legal_holiday_rule: 'weekly',
  four_week_period_start_date: null,
  max_consecutive_work_days: null,
  settlement_start_day: null,
  core_time_enabled: false,
  core_time_start: null,
  core_time_end: null,
  flexible_time_start: null,
  flexible_time_end: null,
  applied_employee_count: 3,
  active_shift_pattern_count: null,
  configuration_warnings: [],
  updated_at: '2026-07-01T09:00:00+09:00',
}

function withSeeded(mode: 'create' | 'edit') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['work-calendars'], [calendar])

  return function Decorator() {
    const [open, setOpen] = useState(true)
    return (
      <QueryClientProvider client={queryClient}>
        <WorkStyleFormModal
          mode={mode}
          workStyle={mode === 'edit' ? workStyle : undefined}
          open={open}
          onOpenChange={setOpen}
        />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Components/WorkStyleFormModal',
  component: WorkStyleFormModal,
} satisfies Meta<typeof WorkStyleFormModal>

export default meta
type Story = StoryObj<typeof meta>

export const Create: Story = {
  args: { mode: 'create', open: true, onOpenChange: () => {} },
  render: withSeeded('create'),
}

export const Edit: Story = {
  args: { mode: 'edit', workStyle, open: true, onOpenChange: () => {} },
  render: withSeeded('edit'),
}
