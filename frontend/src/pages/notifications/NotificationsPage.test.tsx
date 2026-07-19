import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as notificationsApi from '../../api/notifications'
import type { Notification, Paginated } from '../../api/types'
import { NotificationsPage } from './NotificationsPage'

function paginated(data: Notification[]): Paginated<Notification> {
  return { data, meta: { current_page: 1, last_page: 1, total: data.length }, links: { next: null, prev: null } }
}

const unreadNotification: Notification = {
  id: '1',
  title: '承認依頼',
  summary: '「タクシー代」の承認依頼が届いています。',
  detail_url: 'https://example.com/requests/1',
  queued_at: '2026-07-19T09:00:00+09:00',
  sent_at: '2026-07-19T09:00:05+09:00',
  confirmed_at: null,
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <NotificationsPage />
    </QueryClientProvider>,
  )
}

describe('NotificationsPage', () => {
  it('shows an empty state when there are no notifications', async () => {
    vi.spyOn(notificationsApi, 'fetchMyNotifications').mockResolvedValue(paginated([]))

    renderPage()

    expect(await screen.findByText('通知はありません。')).toBeInTheDocument()
  })

  it('shows unread notifications with a confirm button', async () => {
    vi.spyOn(notificationsApi, 'fetchMyNotifications').mockResolvedValue(paginated([unreadNotification]))

    renderPage()

    expect(await screen.findByText('承認依頼')).toBeInTheDocument()
    expect(screen.getAllByText('未読').length).toBeGreaterThan(0)
    expect(screen.getByRole('button', { name: '確認済みにする' })).toBeInTheDocument()
  })

  it('confirms a notification when the button is clicked', async () => {
    vi.spyOn(notificationsApi, 'fetchMyNotifications').mockResolvedValue(paginated([unreadNotification]))
    const confirmSpy = vi
      .spyOn(notificationsApi, 'confirmNotification')
      .mockResolvedValue({ ...unreadNotification, confirmed_at: '2026-07-19T10:00:00+09:00' })

    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '確認済みにする' }))

    await waitFor(() => expect(confirmSpy).toHaveBeenCalledWith('1'))
  })
})
