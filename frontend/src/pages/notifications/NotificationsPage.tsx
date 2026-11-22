import { useEffect } from 'react'
import { Link } from 'react-router-dom'
import { getSafeRedirectTarget } from '../../auth/redirectTarget'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { ListQueueTabs } from '../../components/ListQueueTabs/ListQueueTabs'
import { Pagination } from '../../components/Pagination/Pagination'
import { notify } from '../../lib/notify'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { isValidPageParam } from '../../utils/searchParamValidators'
import { useForwardState, useSearchParamsKeepingBackState } from '../../navigation/backState'
import { useConfirmNotification, useMyNotifications } from '../../hooks/useNotifications'

type StatusFilter = 'all' | 'unread' | 'read'

/** 状態タブ。並び順はこの順に固定する。emptyTitleは該当する通知が無いときの文言。 */
const STATUS_TABS: { value: StatusFilter; label: string; emptyTitle: string }[] = [
  { value: 'all', label: 'すべて', emptyTitle: '通知はありません。' },
  { value: 'unread', label: '未読', emptyTitle: '未読の通知はありません。' },
  { value: 'read', label: '既読', emptyTitle: '既読の通知はありません。' },
]

function isStatusFilter(value: string | null): value is StatusFilter {
  return value === 'all' || value === 'unread' || value === 'read'
}

/** UC-N001: 自分宛て通知の一覧・既読管理。 */
export function NotificationsPage() {
  const forwardState = useForwardState()
  const [searchParams, setSearchParams] = useSearchParamsKeepingBackState()
  const statusParam = searchParams.get('status')
  const status: StatusFilter = isStatusFilter(statusParam) ? statusParam : 'all'
  const isInvalidStatus = statusParam !== null && !isStatusFilter(statusParam)
  const pageParam = searchParams.get('page')
  const isInvalidPage = pageParam !== null && !isValidPageParam(pageParam)
  const page = pageParam !== null && isValidPageParam(pageParam) ? Number(pageParam) : 1
  // 不正なstatus・pageは既定表示として扱い、URLからもそのキーを消す
  useEffect(() => {
    if (!isInvalidStatus && !isInvalidPage) return
    setSearchParams(
      (prev) => {
        const params = new URLSearchParams(prev)
        if (isInvalidStatus) params.delete('status')
        if (isInvalidPage) params.delete('page')
        return params
      },
      { replace: true },
    )
  }, [isInvalidStatus, isInvalidPage, setSearchParams])
  function updateParams(patch: Record<string, string | null>) {
    setSearchParams(
      (prev) => {
        const params = new URLSearchParams(prev)
        for (const [key, value] of Object.entries(patch)) {
          if (value === null) params.delete(key)
          else params.set(key, value)
        }
        return params
      },
      { replace: true },
    )
  }
  function setStatus(next: string) {
    updateParams({ status: next === 'all' ? null : next, page: null })
  }
  function changePage(next: number) {
    updateParams({ page: next > 1 ? String(next) : null })
  }
  const { data, error } = useMyNotifications(status === 'all' ? undefined : status, page)
  const confirmNotification = useConfirmNotification()

  const notifications = data?.data ?? []
  const currentTab = STATUS_TABS.find((tab) => tab.value === status) ?? STATUS_TABS[0]

  return (
    <Card title="通知">
      <ListQueueTabs
        aria-label="表示する通知"
        className="mb-4"
        value={status}
        onChange={setStatus}
        tabs={STATUS_TABS.map((tab) => ({ value: tab.value, label: tab.label, count: data?.meta.status_counts[tab.value] }))}
      />
      {!data && !error && <LoadingState />}
      {error && <ErrorMessage error={error} fallback="通知一覧の取得に失敗しました。" />}

      {data && !error && notifications.length === 0 && <EmptyState title={currentTab.emptyTitle} />}

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
                {notification.detail_url &&
                  (getSafeRedirectTarget(notification.detail_url) ? (
                    <Link
                      to={notification.detail_url}
                      state={forwardState}
                      className="mt-1 inline-block text-sm text-primary hover:underline"
                    >
                      詳細を確認する
                    </Link>
                  ) : (
                    <a
                      href={notification.detail_url}
                      className="mt-1 inline-block text-sm text-primary hover:underline"
                    >
                      詳細を確認する
                    </a>
                  ))}
              </div>
              {isUnread && (
                <Button
                  variant="secondary"
                  isLoading={confirmNotification.isPending && confirmNotification.variables === notification.id}
                  onClick={() =>
                    confirmNotification.mutate(notification.id, {
                      onSuccess: () => notify.success('通知を確認済みにしました'),
                    })
                  }
                >
                  確認済みにする
                </Button>
              )}
              {confirmNotification.error && confirmNotification.variables === notification.id && (
                <ErrorMessage error={confirmNotification.error} />
              )}
            </li>
          )
        })}
      </ul>

      {data && !error && (
        <Pagination
          currentPage={data.meta.current_page}
          lastPage={data.meta.last_page}
          total={data.meta.total}
          onPageChange={changePage}
        />
      )}
    </Card>
  )
}
