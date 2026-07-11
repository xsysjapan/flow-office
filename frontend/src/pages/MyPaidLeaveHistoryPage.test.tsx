import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import * as paidLeaveApi from '../api/paidLeave'
import type { StoredEvent } from '../api/types'
import { MyPaidLeaveHistoryPage } from './MyPaidLeaveHistoryPage'

function renderPage(events: StoredEvent[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(paidLeaveApi, 'fetchMyPaidLeaveHistory').mockResolvedValue(events)

  return render(
    <QueryClientProvider client={queryClient}>
      <MyPaidLeaveHistoryPage />
    </QueryClientProvider>,
  )
}

describe('MyPaidLeaveHistoryPage', () => {
  it('shows an empty state when there is no history', async () => {
    renderPage([])

    expect(await screen.findByText('有給履歴はまだありません。')).toBeInTheDocument()
  })

  it('shows each history event with its label and detail', async () => {
    renderPage([
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
    ])

    expect(await screen.findByText('付与')).toBeInTheDocument()
    expect(screen.getByText('10日を付与(有効期限 2027-06-30)')).toBeInTheDocument()
  })
})
