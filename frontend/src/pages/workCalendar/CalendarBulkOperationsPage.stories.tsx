import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { CalendarBulkOperation, WorkStyle } from '../../api/types'
import { CalendarBulkOperationsPage } from './CalendarBulkOperationsPage'

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
  rounding_mode: 'nearest',
  default_break_start_time: null,
  default_break_end_time: null,
  auto_break_enabled: false,
  company_calendar_id: 'calendar-1',
  is_shift_based: false,
  legal_holiday_rule: 'weekly',
  four_week_period_start_date: null,
  max_consecutive_work_days: null,
  settlement_start_day: null,
  core_time_enabled: false,
  core_time_start: null,
  core_time_end: null,
  flexible_time_start: null,
  flexible_time_end: null,
  is_default: true,
  system_generated: false,
  applied_employee_count: 3,
  active_shift_pattern_count: null,
  configuration_warnings: [],
  updated_at: '2026-07-01T09:00:00+09:00',
}

const history: CalendarBulkOperation[] = [
  {
    id: 'bulk-op-1',
    operation_type: 'calendar_apply',
    target_scope: {},
    conflict_policy: 'skip_existing',
    status: 'applied',
    requested_by_user_id: 'admin-1',
    applied_at: '2026-08-11T00:00:00+09:00',
    reverted_at: null,
    reason: '9月分の勤務予定を一括生成',
  },
  {
    id: 'bulk-op-2',
    operation_type: 'bulk_edit',
    target_scope: {},
    conflict_policy: 'overwrite',
    status: 'reverted',
    requested_by_user_id: 'admin-1',
    applied_at: '2026-08-01T00:00:00+09:00',
    reverted_at: '2026-08-02T00:00:00+09:00',
    reason: '誤って設定した休日を取消し',
  },
]

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['work-styles'], [workStyle])
  queryClient.setQueryData(['calendar-bulk-operations'], history)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <CalendarBulkOperationsPage />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/WorkCalendar/CalendarBulkOperationsPage',
  component: CalendarBulkOperationsPage,
} satisfies Meta<typeof CalendarBulkOperationsPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(),
}
