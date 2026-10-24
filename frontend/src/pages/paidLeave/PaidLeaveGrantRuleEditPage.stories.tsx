import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import type { PaidLeaveGrantPolicies, PaidLeaveGrantRule } from '../../api/types'
import { PaidLeaveGrantRuleEditPage } from './PaidLeaveGrantRuleEditPage'

const rule: PaidLeaveGrantRule = {
  id: 1,
  name: '正社員標準ルール',
  work_style_id: null,
  min_attendance_rate: 80,
  first_grant_after_months: 6,
  grant_cycle_months: 12,
  grant_cycle_type: 'mass_grant_month',
  is_active: true,
  steps: [
    { grant_category: 'normal', continuous_service_months: 6, grant_days: 10 },
    { grant_category: 'normal', continuous_service_months: 18, grant_days: 11 },
    { grant_category: 'proportional_4', continuous_service_months: 6, grant_days: 7 },
  ],
}

const policies: PaidLeaveGrantPolicies = {
  version: 'v1',
  normal: [
    { continuous_service_months: 6, grant_days: 10 },
    { continuous_service_months: 18, grant_days: 11 },
  ],
  proportional_version: 'v1',
  proportional: [{ weekly_scheduled_days_category: '4', continuous_service_months: 6, grant_days: 7 }],
}

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['paid-leave', 'grant-rules'], [rule])
  queryClient.setQueryData(['paid-leave', 'grant-policies'], policies)
  queryClient.setQueryData(['work-calendars'], [
    {
      id: 'calendar-1',
      name: '標準カレンダー',
      week_starts_on: 1,
      fiscal_year_start_month: 4,
      fiscal_year_start_day: 1,
      holiday_calendar_source_id: null,
      is_default: true,
      status: 'active',
      weekday_holiday_pattern: { '1': 'working', '2': 'working', '3': 'working', '4': 'working', '5': 'working', '6': 'legal_holiday', '7': 'legal_holiday' },
      allow_daily_holiday_override: false,
    },
  ])

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/admin/paid-leave/rules/1/edit']}>
          <Routes>
            <Route path="/admin/paid-leave/rules/:ruleId/edit" element={<PaidLeaveGrantRuleEditPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/PaidLeave/PaidLeaveGrantRuleEditPage',
  component: PaidLeaveGrantRuleEditPage,
} satisfies Meta<typeof PaidLeaveGrantRuleEditPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(),
}
