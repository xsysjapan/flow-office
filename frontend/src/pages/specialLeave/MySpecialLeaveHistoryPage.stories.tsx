import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { StoredEvent } from '../../api/types'
import { MySpecialLeaveHistoryPage } from './MySpecialLeaveHistoryPage'

const events: StoredEvent[] = [
  {
    id: 'evt-2',
    event_id: 'evt-2',
    aggregate_type: 'special_leave_request',
    aggregate_id: '1',
    version: 1,
    event_type: 'special_leave.requested',
    payload: { target_date: '2026-08-10', leave_type: 'full', requested_days: 1 },
    occurred_at: '2026-08-05T09:00:00+09:00',
  },
  {
    id: 'evt-1',
    event_id: 'evt-1',
    aggregate_type: 'special_leave_grant',
    aggregate_id: '1',
    version: 1,
    event_type: 'special_leave.granted',
    payload: { granted_days: 3, expires_on: null },
    occurred_at: '2026-07-01T09:00:00+09:00',
  },
]

function withSeeded(data: StoredEvent[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['special-leave', 'history', 'mine'], data)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MySpecialLeaveHistoryPage />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/SpecialLeave/MySpecialLeaveHistoryPage',
  component: MySpecialLeaveHistoryPage,
} satisfies Meta<typeof MySpecialLeaveHistoryPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(events),
}

export const Empty: Story = {
  render: withSeeded([]),
}
