import { Link, NavLink, Outlet, useLocation } from 'react-router-dom'
import { Briefcase, CalendarClock, CheckCircle2, ChevronDown, FileText, Settings, type LucideIcon } from 'lucide-react'
import { useAuth } from '../../auth/useAuth'
import { cn } from '../../lib/utils'
import { hasAnyRole, ROLE, ROLE_LABEL, type RoleCode } from '../../utils/roles'
import { Button } from '../Button/Button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '../ui/dropdown-menu'

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
      { to: '/paid-leave/history', label: '有給履歴' },
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

/** そのナビ項目(またはその配下のページ)を今表示しているか。前方一致するパス同士(有給/有給履歴等)が
 *  同時にアクティブにならないよう、"/"は完全一致、それ以外は自身か"to/"始まりのパスのみ一致させる。 */
function isItemActive(pathname: string, to: string): boolean {
  if (to === '/') return pathname === '/'
  return pathname === to || pathname.startsWith(`${to}/`)
}

const navTriggerClass =
  'flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm whitespace-nowrap text-muted-foreground outline-none transition-colors hover:bg-accent hover:text-foreground data-[state=open]:bg-accent data-[state=open]:text-foreground'

function NavGroupMenu({ group }: { group: NavGroup }) {
  const { pathname } = useLocation()
  const active = group.items.some((item) => isItemActive(pathname, item.to))

  if (group.items.length === 1) {
    const item = group.items[0]
    return (
      <NavLink to={item.to} className={cn(navTriggerClass, active && 'font-medium text-foreground')}>
        <group.icon className="size-4 shrink-0" aria-hidden="true" />
        {item.label}
      </NavLink>
    )
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger className={cn(navTriggerClass, active && 'font-medium text-foreground')}>
        <group.icon className="size-4 shrink-0" aria-hidden="true" />
        {group.label}
        <ChevronDown className="size-3.5 shrink-0" aria-hidden="true" />
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start">
        {group.items.map((item) => (
          <DropdownMenuItem key={item.to} asChild>
            {/* Radix の asChild は文字列でないclassName(NavLinkの関数形式)をそのまま
                文字列連結してしまうため、LinkとisActiveの事前計算(exact一致)で対応する。 */}
            <Link to={item.to} className={cn('w-full', pathname === item.to && 'font-medium text-primary')}>
              {item.label}
            </Link>
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

export function AppLayout() {
  const { user, logout } = useAuth()
  const visibleGroups = navGroups.filter((group) => !group.roles || hasAnyRole(user?.roles, group.roles))

  return (
    <div className="flex min-h-screen flex-col">
      <header className="flex flex-col gap-2 border-b border-border bg-card px-4 py-3 sm:px-6 lg:px-8">
        <div className="flex items-center justify-between gap-4">
          <span className="text-sm font-semibold text-foreground">flow-office</span>
          <div className="flex items-center gap-3">
            {user && (
              <div className="flex flex-col items-end leading-tight">
                <span className="text-sm text-muted-foreground">{user.name}</span>
                <span className="text-xs text-muted-foreground">
                  {[user.department, user.roles?.map((role) => ROLE_LABEL[role as RoleCode] ?? role).join(' / ')]
                    .filter(Boolean)
                    .join(' ・ ')}
                </span>
              </div>
            )}
            <Button variant="secondary" onClick={() => void logout()}>
              ログアウト
            </Button>
          </div>
        </div>
        <nav className="flex flex-wrap items-center gap-1" aria-label="メインナビゲーション">
          {visibleGroups.map((group) => (
            <NavGroupMenu key={group.label} group={group} />
          ))}
        </nav>
      </header>
      <main className="mx-auto w-full max-w-4xl flex-1 p-4 sm:p-6 lg:max-w-6xl lg:p-8">
        <Outlet />
      </main>
    </div>
  )
}
