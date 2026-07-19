import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { Notification, Paginated } from '../../api/types'
import { NotificationsPage } from './NotificationsPage'

const notifications: Notification[] = [
  {
    id: '1',
    title: '承認依頼',
    summary: '「タクシー代」の承認依頼が届いています。',
    detail_url: 'https://example.com/requests/1',
    queued_at: '2026-07-19T09:00:00+09:00',
    sent_at: '2026-07-19T09:00:05+09:00',
    confirmed_at: null,
  },
  {
    id: '2',
    title: '承認完了',
    summary: '「有給申請」が承認されました。',
    detail_url: null,
    queued_at: '2026-07-18T09:00:00+09:00',
    sent_at: '2026-07-18T09:00:05+09:00',
    confirmed_at: '2026-07-18T10:00:00+09:00',
  },
]

function withSeeded(data: Notification[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  const page: Paginated<Notification> = {
    data,
    meta: { current_page: 1, last_page: 1, total: data.length },
    links: { next: null, prev: null },
  }
  queryClient.setQueryData(['notifications', 'mine', 'all'], page)

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <NotificationsPage />
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/Notifications/NotificationsPage',
  component: NotificationsPage,
} satisfies Meta<typeof NotificationsPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: withSeeded(notifications),
}

export const Empty: Story = {
  render: withSeeded([]),
}
