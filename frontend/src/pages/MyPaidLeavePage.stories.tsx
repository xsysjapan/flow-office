import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { PaidLeaveGrant } from '../api/types'
import { MyPaidLeavePage } from './MyPaidLeavePage'

const grants: PaidLeaveGrant[] = [
  {
    id: 1,
    user_id: 1,
    granted_on: '2025-04-01',
    expires_on: '2027-03-31',
    granted_days: 10,
    used_days: 3,
    remaining_days: 7,
    grant_reason: '法定付与',
  },
  {
    id: 2,
    user_id: 1,
    granted_on: '2026-04-01',
    expires_on: '2028-03-31',
    granted_days: 11,
    used_days: 0,
    remaining_days: 11,
    grant_reason: null,
  },
]

function withSeeded(data: PaidLeaveGrant[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['paid-leave', 'grants', 'mine'], data)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MyPaidLeavePage />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/MyPaidLeavePage',
  component: MyPaidLeavePage,
} satisfies Meta<typeof MyPaidLeavePage>

export default meta
type Story = StoryObj<typeof meta>

export const WithGrants: Story = {
  render: withSeeded(grants),
}

export const Empty: Story = {
  render: withSeeded([]),
}
