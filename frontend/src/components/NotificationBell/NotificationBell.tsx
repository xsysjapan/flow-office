import { Bell } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useMyNotifications } from '../../hooks/useNotifications'

/** ヘッダーに置く通知ベル。未読件数をバッジ表示し、通知一覧(/notifications)へ遷移する。 */
export function NotificationBell() {
  const { data } = useMyNotifications('unread')
  const unreadCount = data?.meta.total ?? 0

  return (
    <Link
      to="/notifications"
      aria-label={unreadCount > 0 ? `通知(未読${unreadCount}件)` : '通知'}
      className="relative flex size-9 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
    >
      <Bell className="size-5" aria-hidden="true" />
      {unreadCount > 0 && (
        <span className="absolute right-1 top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-medium text-destructive-foreground">
          {unreadCount > 99 ? '99+' : unreadCount}
        </span>
      )}
    </Link>
  )
}
