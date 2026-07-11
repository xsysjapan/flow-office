import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { StoredEvent } from '../api/types'
import { MyPaidLeaveHistoryPage } from './MyPaidLeaveHistoryPage'

const events: StoredEvent[] = [
  {
    id: 2,
    event_id: 'evt-2',
    aggregate_type: 'paid_leave_request',
    aggregate_id: '1',
    version: 1,
    event_type: 'paid_leave.requested',
    payload: { target_date: '2026-08-10', leave_type: 'full', requested_days: 1 },
    occurred_at: '2026-08-05T09:00:00+09:00',
  },
  {
    id: 1,
    event_id: 'evt-1',
    aggregate_type: 'paid_leave_grant',
    aggregate_id: '1',
    version: 1,
    event_type: 'paid_leave.granted',
    payload: { granted_days: 10, expires_on: '2027-06-30' },
    occurred_at: '2025-07-01T09:00:00+09:00',
  },
]

function withSeeded(data: StoredEvent[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['paid-leave', 'history', 'mine'], data)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MyPaidLeaveHistoryPage />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/MyPaidLeaveHistoryPage',
  component: MyPaidLeaveHistoryPage,
} satisfies Meta<typeof MyPaidLeaveHistoryPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(events),
}

export const Empty: Story = {
  render: withSeeded([]),
}
