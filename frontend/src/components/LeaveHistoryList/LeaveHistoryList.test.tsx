import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { StoredEvent } from '../../api/types'
import { LeaveHistoryList } from './LeaveHistoryList'

const grantedEvent: StoredEvent = {
  id: 1,
  event_id: 'evt-1',
  aggregate_type: 'paid_leave_grant',
  aggregate_id: '1',
  version: 1,
  event_type: 'paid_leave.granted',
  payload: { granted_days: 10, expires_on: '2027-06-30' },
  occurred_at: '2025-07-01T09:00:00+09:00',
}

const requestedEvent: StoredEvent = {
  id: 2,
  event_id: 'evt-2',
  aggregate_type: 'paid_leave_request',
  aggregate_id: '1',
  version: 1,
  event_type: 'paid_leave.requested',
  payload: { target_date: '2026-08-10', leave_type: 'full', requested_days: 1 },
  occurred_at: '2026-08-05T09:00:00+09:00',
}

describe('LeaveHistoryList', () => {
  it('shows a loading state', () => {
    render(<LeaveHistoryList domain="paid_leave" events={undefined} isLoading />)
    expect(screen.getByRole('status')).toBeInTheDocument()
  })

  it('shows an error message', () => {
    render(<LeaveHistoryList domain="paid_leave" events={undefined} isLoading={false} error={new Error('失敗')} />)
    expect(screen.getByText('失敗')).toBeInTheDocument()
  })

  it('shows an empty state when there is no history', () => {
    render(<LeaveHistoryList domain="paid_leave" events={[]} isLoading={false} />)
    expect(screen.getByText('有給履歴はまだありません。')).toBeInTheDocument()
  })

  it('renders each event with its label and payload-derived detail', () => {
    render(<LeaveHistoryList domain="paid_leave" events={[requestedEvent, grantedEvent]} isLoading={false} />)

    expect(screen.getByText('付与')).toBeInTheDocument()
    expect(screen.getByText('10日を付与(有効期限 2027-06-30)')).toBeInTheDocument()
    expect(screen.getByText('申請')).toBeInTheDocument()
    expect(screen.getByText('対象日 2026-08-10 の全休を申請(1日)')).toBeInTheDocument()
  })

  it('shows the special leave empty state and formats special leave grants without an expiry', () => {
    const { rerender } = render(<LeaveHistoryList domain="special_leave" events={[]} isLoading={false} />)
    expect(screen.getByText('特別休暇履歴はまだありません。')).toBeInTheDocument()

    const specialLeaveGrantedEvent: StoredEvent = {
      ...grantedEvent,
      event_type: 'special_leave.granted',
      payload: { granted_days: 3, expires_on: null },
    }
    rerender(<LeaveHistoryList domain="special_leave" events={[specialLeaveGrantedEvent]} isLoading={false} />)

    expect(screen.getByText('3日を付与(有効期限なし)')).toBeInTheDocument()
  })
})
