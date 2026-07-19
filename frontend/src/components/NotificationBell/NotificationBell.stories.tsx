import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { Notification, Paginated } from '../../api/types'
import { NotificationBell } from './NotificationBell'

function withSeeded(unread: Notification[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  const data: Paginated<Notification> = {
    data: unread,
    meta: { current_page: 1, last_page: 1, total: unread.length },
    links: { next: null, prev: null },
  }
  queryClient.setQueryData(['notifications', 'mine', 'unread'], data)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <NotificationBell />
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const sampleNotification: Notification = {
  id: '1',
  title: '承認依頼',
  summary: '「タクシー代」の承認依頼が届いています。',
  detail_url: null,
  queued_at: '2026-07-19T09:00:00+09:00',
  sent_at: '2026-07-19T09:00:05+09:00',
  confirmed_at: null,
}

const meta = {
  title: 'Components/NotificationBell',
  component: NotificationBell,
} satisfies Meta<typeof NotificationBell>

export default meta
type Story = StoryObj<typeof meta>

export const NoUnread: Story = {
  render: withSeeded([]),
}

export const WithUnread: Story = {
  render: withSeeded([sampleNotification]),
}
