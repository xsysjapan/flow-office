import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as notificationsApi from '../../api/notifications'
import type { MyNotificationsResult, Notification } from '../../api/types'
import { NotificationsPage } from './NotificationsPage'
import { notify } from '../../lib/notify'

vi.mock('../../lib/notify', () => ({ notify: { success: vi.fn() } }))

const statusCounts = { all: 7, unread: 3, read: 4 }

function paginated(data: Notification[], meta: { current_page?: number; last_page?: number } = {}): MyNotificationsResult {
  return {
    data,
    meta: { current_page: meta.current_page ?? 1, last_page: meta.last_page ?? 1, total: data.length, status_counts: statusCounts },
    links: { next: null, prev: null },
  }
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

const relativeNotification: Notification = { ...unreadNotification, id: '2', detail_url: '/requests/1' }

function LocationProbe() {
  const location = useLocation()
  return <div data-testid="location-search">{location.search}</div>
}

function renderPage(initialEntries: string[] = ['/notifications']) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={initialEntries}>
        <NotificationsPage />
        <LocationProbe />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('NotificationsPage', () => {
  beforeEach(() => {
    vi.mocked(notify.success).mockClear()
  })

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

  it('renders an in-app detail_url as a router link and an external one as a plain anchor', async () => {
    vi.spyOn(notificationsApi, 'fetchMyNotifications').mockResolvedValue(
      paginated([relativeNotification, { ...unreadNotification, id: '3' }]),
    )

    renderPage()

    await screen.findAllByRole('link', { name: '詳細を確認する' })
    const links = screen.getAllByRole('link', { name: '詳細を確認する' })
    expect(links[0]).toHaveAttribute('href', '/requests/1')
    expect(links[1]).toHaveAttribute('href', 'https://example.com/requests/1')
  })

  it('confirms a notification when the button is clicked', async () => {
    vi.spyOn(notificationsApi, 'fetchMyNotifications').mockResolvedValue(paginated([unreadNotification]))
    const confirmSpy = vi
      .spyOn(notificationsApi, 'confirmNotification')
      .mockResolvedValue({ ...unreadNotification, confirmed_at: '2026-07-19T10:00:00+09:00' })

    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: '確認済みにする' }))

    await waitFor(() => expect(confirmSpy).toHaveBeenCalledWith('1'))
    await waitFor(() => expect(notify.success).toHaveBeenCalledWith('通知を確認済みにしました'))
  })

  it('drops an invalid status from the URL and shows all notifications', async () => {
    const spy = vi.spyOn(notificationsApi, 'fetchMyNotifications').mockResolvedValue(paginated([unreadNotification]))

    renderPage(['/notifications?status=bogus'])

    expect(await screen.findByText('承認依頼')).toBeInTheDocument()
    await waitFor(() => expect(screen.getByTestId('location-search')).toBeEmptyDOMElement())
    expect(spy).toHaveBeenLastCalledWith(undefined, undefined)
  })

  it('keeps a valid status in the URL', async () => {
    vi.spyOn(notificationsApi, 'fetchMyNotifications').mockResolvedValue(paginated([unreadNotification]))

    renderPage(['/notifications?status=unread'])

    expect(await screen.findByText('承認依頼')).toBeInTheDocument()
    expect(screen.getByTestId('location-search')).toHaveTextContent('?status=unread')
  })

  it('shows the count on each status tab', async () => {
    vi.spyOn(notificationsApi, 'fetchMyNotifications').mockResolvedValue(paginated([unreadNotification]))

    renderPage()

    expect(await screen.findByRole('tab', { name: 'すべて 7件' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: '未読 3件' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: '既読 4件' })).toBeInTheDocument()
  })

  it('switching the tab puts status in the URL and drops page', async () => {
    const spy = vi.spyOn(notificationsApi, 'fetchMyNotifications').mockResolvedValue(paginated([unreadNotification]))

    renderPage(['/notifications?page=2'])
    await screen.findByText('承認依頼')

    await userEvent.click(screen.getByRole('tab', { name: '未読 3件' }))

    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent('?status=unread'))
    expect(screen.getByTestId('location-search')).not.toHaveTextContent('page')
    expect(spy).toHaveBeenLastCalledWith('unread', undefined)
  })

  it('moves to page 2 and puts it in the URL', async () => {
    const spy = vi
      .spyOn(notificationsApi, 'fetchMyNotifications')
      .mockResolvedValue(paginated([unreadNotification], { last_page: 2 }))

    renderPage()
    await screen.findByText('承認依頼')

    await userEvent.click(screen.getByRole('button', { name: '次のページ' }))

    await waitFor(() => expect(screen.getByTestId('location-search')).toHaveTextContent('?page=2'))
    expect(spy).toHaveBeenLastCalledWith(undefined, 2)
  })

  it('shows the tab specific empty state for unread', async () => {
    vi.spyOn(notificationsApi, 'fetchMyNotifications').mockResolvedValue(paginated([]))

    renderPage(['/notifications?status=unread'])

    expect(await screen.findByText('未読の通知はありません。')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'すべて表示する' })).not.toBeInTheDocument()
  })

  it('keeps the tabs when the fetch fails', async () => {
    vi.spyOn(notificationsApi, 'fetchMyNotifications').mockRejectedValue(new Error('boom'))

    renderPage()

    expect(await screen.findByRole('tab', { name: 'すべて' })).toBeInTheDocument()
    expect(await screen.findByText(/boom|通知一覧の取得に失敗しました。/)).toBeInTheDocument()
  })
})
