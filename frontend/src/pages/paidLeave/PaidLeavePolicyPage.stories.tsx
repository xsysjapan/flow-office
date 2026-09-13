import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { Paginated, PaidLeaveGrantPolicies, PaidLeaveGrantRule, User } from '../../api/types'
import { PaidLeavePolicyPage } from './PaidLeavePolicyPage'

const rules: PaidLeaveGrantRule[] = [
  {
    id: 1,
    name: '正社員標準ルール',
    work_style_id: null,
    min_attendance_rate: 0.8,
    first_grant_after_months: 6,
    grant_cycle_months: 12,
    is_active: true,
    steps: [
      { continuous_service_months: 6, grant_days: 10 },
      { continuous_service_months: 18, grant_days: 11 },
    ],
  },
]

const policies: PaidLeaveGrantPolicies = {
  version: 'v1',
  normal: [
    { continuous_service_months: 6, grant_days: 10 },
    { continuous_service_months: 18, grant_days: 11 },
    { continuous_service_months: 30, grant_days: 12 },
  ],
  proportional_version: 'v1',
  proportional: [
    { weekly_scheduled_days_category: '4', continuous_service_months: 6, grant_days: 7 },
    { weekly_scheduled_days_category: '3', continuous_service_months: 6, grant_days: 5 },
  ],
}

const paginatedUsers: Paginated<User> = {
  data: [],
  meta: { current_page: 1, last_page: 1, total: 0 },
  links: { next: null, prev: null },
}

function withSeeded(seedRules: PaidLeaveGrantRule[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['paid-leave', 'grant-rules'], seedRules)
  queryClient.setQueryData(['paid-leave', 'grant-policies'], policies)
  queryClient.setQueryData(['users', 'search', '', 100], paginatedUsers)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <PaidLeavePolicyPage />
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/PaidLeave/PaidLeavePolicyPage',
  component: PaidLeavePolicyPage,
} satisfies Meta<typeof PaidLeavePolicyPage>

export default meta
type Story = StoryObj<typeof meta>

export const WithRules: Story = {
  render: withSeeded(rules),
}

export const NoRules: Story = {
  render: withSeeded([]),
}
