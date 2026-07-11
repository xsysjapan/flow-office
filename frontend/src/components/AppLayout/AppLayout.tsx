import { NavLink, Outlet } from 'react-router-dom'
import { Briefcase, CalendarClock, CheckCircle2, FileText, Settings, type LucideIcon } from 'lucide-react'
import { useAuth } from '../../auth/useAuth'
import { cn } from '../../lib/utils'
import { hasAnyRole, ROLE, type RoleCode } from '../../utils/roles'
import { Button } from '../Button/Button'

interface NavItem {
  to: string
  label: string
}

interface NavGroup {
  label: string
  icon: LucideIcon
  items: NavItem[]
  /** 未指定なら全ユーザーに表示。指定時はいずれかのロールを持つユーザーにのみグループごと表示する。 */
  roles?: RoleCode[]
}

const navGroups: NavGroup[] = [
  {
    label: '勤怠',
    icon: CalendarClock,
    items: [
      { to: '/', label: '今日の勤怠' },
      { to: '/attendance/week', label: '週次勤怠' },
      { to: '/attendance/months', label: '勤怠月次' },
      { to: '/paid-leave', label: '有給' },
    ],
  },
  {
    label: '申請',
    icon: FileText,
    items: [
      { to: '/requests', label: '自分の申請' },
      { to: '/requests/new', label: '新規申請' },
    ],
  },
  {
    label: '承認',
    icon: CheckCircle2,
    items: [
      { to: '/approvals', label: '承認待ち' },
      { to: '/attendance/months/to-approve', label: '勤怠月次承認' },
      { to: '/paid-leave/to-approve', label: '有給申請承認' },
    ],
  },
  {
    label: 'バックオフィス',
    icon: Briefcase,
    roles: [ROLE.BACKOFFICE_STAFF, ROLE.ACCOUNTING_STAFF, ROLE.GENERAL_AFFAIRS_STAFF, ROLE.ADMIN],
    items: [{ to: '/backoffice-tasks', label: 'タスク一覧' }],
  },
  {
    label: '管理',
    icon: Settings,
    roles: [ROLE.ADMIN, ROLE.HR_STAFF],
    items: [{ to: '/admin', label: '管理メニュー' }],
  },
]

export function AppLayout() {
  const { user, logout } = useAuth()
  const visibleGroups = navGroups.filter((group) => !group.roles || hasAnyRole(user?.roles, group.roles))

  return (
    <div className="flex min-h-screen flex-col">
      <header className="flex flex-col gap-3 border-b border-border px-4 py-3 sm:px-6">
        <div className="flex items-center justify-between gap-4">
          <span className="text-sm font-semibold text-foreground">flow-office</span>
          <div className="flex items-center gap-3">
            {user && <span className="text-sm text-muted-foreground">{user.name}</span>}
            <Button variant="secondary" onClick={() => void logout()}>
              ログアウト
            </Button>
          </div>
        </div>
        <nav className="flex flex-wrap items-start gap-x-6 gap-y-3" aria-label="メインナビゲーション">
          {visibleGroups.map((group) => (
            <div key={group.label} className="flex items-center gap-2">
              <group.icon className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
              <div className="flex flex-col gap-1">
                <span className="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                  {group.label}
                </span>
                <div className="flex flex-wrap gap-x-4 gap-y-1">
                  {group.items.map((item) => (
                    <NavLink
                      key={item.to}
                      to={item.to}
                      end={item.to === '/'}
                      className={({ isActive }) =>
                        cn(
                          'text-sm whitespace-nowrap text-muted-foreground transition-colors hover:text-foreground',
                          isActive && 'font-semibold text-primary',
                        )
                      }
                    >
                      {item.label}
                    </NavLink>
                  ))}
                </div>
              </div>
            </div>
          ))}
        </nav>
      </header>
      <main className="mx-auto w-full max-w-4xl flex-1 p-4 sm:p-6">
        <Outlet />
      </main>
    </div>
  )
}
