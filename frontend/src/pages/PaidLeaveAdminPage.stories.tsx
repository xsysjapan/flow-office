import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { Paginated, PaidLeaveGrantRule, User } from '../api/types'
import { PaidLeaveAdminPage } from './PaidLeaveAdminPage'

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

const paginatedUsers: Paginated<User> = {
  data: [],
  meta: { current_page: 1, last_page: 1, total: 0 },
  links: { next: null, prev: null },
}

function withSeeded(seedRules: PaidLeaveGrantRule[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['paid-leave', 'grant-rules'], seedRules)
  queryClient.setQueryData(['users', ''], paginatedUsers)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <PaidLeaveAdminPage />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/PaidLeaveAdminPage',
  component: PaidLeaveAdminPage,
} satisfies Meta<typeof PaidLeaveAdminPage>

export default meta
type Story = StoryObj<typeof meta>

export const WithRules: Story = {
  render: withSeeded(rules),
}

export const NoRules: Story = {
  render: withSeeded([]),
}
