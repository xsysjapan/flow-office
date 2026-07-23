import type { Meta, StoryObj } from '@storybook/react-vite'
import type { StoredEvent } from '../../api/types'
import { LeaveHistoryList } from './LeaveHistoryList'

const events: StoredEvent[] = [
  {
    id: 'evt-4',
    event_id: 'evt-4',
    aggregate_type: 'paid_leave_grant',
    aggregate_id: '1',
    version: 2,
    event_type: 'paid_leave.used',
    payload: { used_on: '2026-08-10', used_days: 1 },
    occurred_at: '2026-08-11T10:00:00+09:00',
  },
  {
    id: 'evt-3',
    event_id: 'evt-3',
    aggregate_type: 'paid_leave_request',
    aggregate_id: '1',
    version: 2,
    event_type: 'paid_leave.request_approved',
    payload: {},
    occurred_at: '2026-08-11T10:00:00+09:00',
  },
  {
    id: 'evt-2',
    event_id: 'evt-2',
    aggregate_type: 'paid_leave_request',
    aggregate_id: '1',
    version: 1,
    event_type: 'paid_leave.requested',
    payload: { target_date: '2026-08-10', leave_type: 'full', requested_days: 1 },
    occurred_at: '2026-08-05T09:00:00+09:00',
  },
  {
    id: 'evt-1',
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
  title: 'Components/LeaveHistoryList',
  component: LeaveHistoryList,
} satisfies Meta<typeof LeaveHistoryList>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: { domain: 'paid_leave', events, isLoading: false },
}

export const SpecialLeave: Story = {
  args: {
    domain: 'special_leave',
    events: events.map((event) => ({ ...event, event_type: event.event_type.replace('paid_leave', 'special_leave') })),
    isLoading: false,
  },
}

export const Loading: Story = {
  args: { domain: 'paid_leave', events: undefined, isLoading: true },
}

export const Empty: Story = {
  args: { domain: 'paid_leave', events: [], isLoading: false },
}

export const WithError: Story = {
  args: { domain: 'paid_leave', events: undefined, isLoading: false, error: new Error('取得に失敗しました') },
}
