import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import * as specialLeaveApi from '../../api/specialLeave'
import type { StoredEvent } from '../../api/types'
import { MySpecialLeaveHistoryPage } from './MySpecialLeaveHistoryPage'

function renderPage(events: StoredEvent[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(specialLeaveApi, 'fetchMySpecialLeaveHistory').mockResolvedValue(events)

  return render(
    <QueryClientProvider client={queryClient}>
      <MySpecialLeaveHistoryPage />
    </QueryClientProvider>,
  )
}

describe('MySpecialLeaveHistoryPage', () => {
  it('shows an empty state when there is no history', async () => {
    renderPage([])

    expect(await screen.findByText('特別休暇履歴はまだありません。')).toBeInTheDocument()
  })

  it('shows each history event with its label and detail, including a grant with no expiry', async () => {
    renderPage([
      {
        id: 1,
        event_id: 'evt-1',
        aggregate_type: 'special_leave_grant',
        aggregate_id: '1',
        version: 1,
        event_type: 'special_leave.granted',
        payload: { granted_days: 3, expires_on: null },
        occurred_at: '2026-07-01T09:00:00+09:00',
      },
    ])

    expect(await screen.findByText('付与')).toBeInTheDocument()
    expect(screen.getByText('3日を付与(有効期限なし)')).toBeInTheDocument()
  })
})
