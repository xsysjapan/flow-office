import { useState } from 'react'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { useConfirmNotification, useMyNotifications } from '../../hooks/useNotifications'

type StatusFilter = 'all' | 'unread' | 'read'

const FILTERS: { value: StatusFilter; label: string }[] = [
  { value: 'all', label: 'すべて' },
  { value: 'unread', label: '未読' },
  { value: 'read', label: '既読' },
]

/** UC-N001: 自分宛て通知の一覧・既読管理。 */
export function NotificationsPage() {
  const [status, setStatus] = useState<StatusFilter>('all')
  const { data, isLoading, error } = useMyNotifications(status === 'all' ? undefined : status)
  const confirmNotification = useConfirmNotification()

  const notifications = data?.data ?? []

  return (
    <Card
      title="通知"
      actions={
        <div className="flex items-center gap-1">
          {FILTERS.map((filter) => (
            <Button
              key={filter.value}
              variant={status === filter.value ? 'primary' : 'secondary'}
              onClick={() => setStatus(filter.value)}
            >
              {filter.label}
            </Button>
          ))}
        </div>
      }
    >
      {isLoading && <LoadingState />}
      {error && <ErrorMessage error={error} fallback="通知一覧の取得に失敗しました。" />}
      {confirmNotification.error && <ErrorMessage error={confirmNotification.error} />}

      {!isLoading && !error && notifications.length === 0 && (
        <p className="text-sm text-muted-foreground">通知はありません。</p>
      )}

      <ul className="divide-y divide-border">
        {notifications.map((notification) => {
          const isUnread = notification.confirmed_at === null
          return (
            <li key={notification.id} className="flex items-start justify-between gap-4 py-3">
              <div className="min-w-0 flex-1">
                <div className="mb-1 flex items-center gap-2">
                  {isUnread && <Badge tone="info">未読</Badge>}
                  <span className="font-medium text-foreground">{notification.title}</span>
                </div>
                <p className="text-sm text-muted-foreground">{notification.summary}</p>
                {notification.detail_url && (
                  <a
                    href={notification.detail_url}
                    className="mt-1 inline-block text-sm text-primary hover:underline"
                  >
                    詳細を確認する
                  </a>
                )}
              </div>
              {isUnread && (
                <Button
                  variant="secondary"
                  isLoading={confirmNotification.isPending && confirmNotification.variables === notification.id}
                  onClick={() => confirmNotification.mutate(notification.id)}
                >
                  確認済みにする
                </Button>
              )}
            </li>
          )
        })}
      </ul>
    </Card>
  )
}
