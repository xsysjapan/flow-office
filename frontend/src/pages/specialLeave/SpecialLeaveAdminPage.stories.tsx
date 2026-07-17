import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { Paginated, SpecialLeaveGrantRule, SpecialLeaveType, User } from '../../api/types'
import { SpecialLeaveAdminPage } from './SpecialLeaveAdminPage'

const types: SpecialLeaveType[] = [
  { id: 1, name: '誕生日休暇', is_active: true },
  { id: 2, name: 'リフレッシュ休暇', is_active: true },
]

const rules: SpecialLeaveGrantRule[] = [
  {
    id: 1,
    special_leave_type_id: 1,
    special_leave_type_name: '誕生日休暇',
    name: '誕生日休暇ルール',
    work_style_id: null,
    min_attendance_rate: 80,
    first_grant_after_months: 0,
    grant_cycle_months: 12,
    expires_after_months: 6,
    is_active: true,
    steps: [{ continuous_service_months: 0, grant_days: 1 }],
  },
]

const paginatedUsers: Paginated<User> = {
  data: [],
  meta: { current_page: 1, last_page: 1, total: 0 },
  links: { next: null, prev: null },
}

function withSeeded(seedTypes: SpecialLeaveType[], seedRules: SpecialLeaveGrantRule[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['special-leave', 'types'], seedTypes)
  queryClient.setQueryData(['special-leave', 'grant-rules'], seedRules)
  queryClient.setQueryData(['users', ''], paginatedUsers)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <SpecialLeaveAdminPage />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/SpecialLeave/SpecialLeaveAdminPage',
  component: SpecialLeaveAdminPage,
} satisfies Meta<typeof SpecialLeaveAdminPage>

export default meta
type Story = StoryObj<typeof meta>

export const WithTypesAndRules: Story = {
  render: withSeeded(types, rules),
}

export const NoTypes: Story = {
  render: withSeeded([], []),
}
