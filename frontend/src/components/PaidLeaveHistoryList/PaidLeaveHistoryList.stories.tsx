import type { Meta, StoryObj } from '@storybook/react-vite'
import type { StoredEvent } from '../../api/types'
import { PaidLeaveHistoryList } from './PaidLeaveHistoryList'

const events: StoredEvent[] = [
  {
    id: 4,
    event_id: 'evt-4',
    aggregate_type: 'paid_leave_grant',
    aggregate_id: '1',
    version: 2,
    event_type: 'paid_leave.used',
    payload: { used_on: '2026-08-10', used_days: 1 },
    occurred_at: '2026-08-11T10:00:00+09:00',
  },
  {
    id: 3,
    event_id: 'evt-3',
    aggregate_type: 'paid_leave_request',
    aggregate_id: '1',
    version: 2,
    event_type: 'paid_leave.request_approved',
    payload: {},
    occurred_at: '2026-08-11T10:00:00+09:00',
  },
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

const meta = {
  title: 'Components/PaidLeaveHistoryList',
  component: PaidLeaveHistoryList,
} satisfies Meta<typeof PaidLeaveHistoryList>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: { events, isLoading: false },
}

export const Loading: Story = {
  args: { events: undefined, isLoading: true },
}

export const Empty: Story = {
  args: { events: [], isLoading: false },
}

export const WithError: Story = {
  args: { events: undefined, isLoading: false, error: new Error('取得に失敗しました') },
}
