import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import * as notificationsApi from '../../api/notifications'
import type { Notification, Paginated } from '../../api/types'
import { NotificationBell } from './NotificationBell'

function renderBell() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <NotificationBell />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function paginated(data: Notification[]): Paginated<Notification> {
  return { data, meta: { current_page: 1, last_page: 1, total: data.length }, links: { next: null, prev: null } }
}

describe('NotificationBell', () => {
  it('shows no badge when there are no unread notifications', async () => {
    vi.spyOn(notificationsApi, 'fetchMyNotifications').mockResolvedValue(paginated([]))

    renderBell()

    expect(await screen.findByLabelText('通知')).toBeInTheDocument()
    expect(screen.queryByText('0')).not.toBeInTheDocument()
  })

  it('shows the unread count as a badge', async () => {
    vi.spyOn(notificationsApi, 'fetchMyNotifications').mockResolvedValue(
      paginated([
        {
          id: '1',
          title: '承認依頼',
          summary: '概要',
          detail_url: null,
          queued_at: '2026-07-19T00:00:00+09:00',
          sent_at: null,
          confirmed_at: null,
        },
      ]),
    )

    renderBell()

    expect(await screen.findByLabelText('通知(未読1件)')).toBeInTheDocument()
    expect(screen.getByText('1')).toBeInTheDocument()
  })
})
