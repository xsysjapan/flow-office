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
  is_active: true,
  steps: [
    { continuous_service_months: 6, grant_days: 10 },
    { continuous_service_months: 18, grant_days: 11 },
  ],
}

const policies: PaidLeaveGrantPolicies = {
  version: 'v1',
  normal: [
    { continuous_service_months: 6, grant_days: 10 },
    { continuous_service_months: 18, grant_days: 11 },
  ],
  proportional_version: 'v1',
  proportional: [],
}

function withSeeded() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['paid-leave', 'grant-rules'], [rule])
  queryClient.setQueryData(['paid-leave', 'grant-policies'], policies)

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
