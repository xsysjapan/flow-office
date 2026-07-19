import { useState } from 'react'
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom'
import { Briefcase, CalendarClock, CheckCircle2, ChevronDown, FileText, Menu, Plug, Settings, type LucideIcon } from 'lucide-react'
import { useAuth } from '../../auth/useAuth'
import { useSpecialLeaveTypes } from '../../hooks/useSpecialLeave'
import { cn } from '../../lib/utils'
import { hasAnyRole, ROLE, ROLE_LABEL, type RoleCode } from '../../utils/roles'
import { formatDate } from '../../utils/weekDates'
import { Button } from '../Button/Button'
import { NotificationBell } from '../NotificationBell/NotificationBell'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '../ui/dropdown-menu'
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '../ui/sheet'

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

function navGroups(currentYearMonth: string, hasSpecialLeaveTypes: boolean): NavGroup[] {
  return [
  {
    label: '勤怠',
    icon: CalendarClock,
    items: [
      { to: '/', label: '今日の勤怠' },
      { to: '/attendance/week', label: '週次勤怠' },
      { to: `/attendance/months/${currentYearMonth}`, label: '月次勤怠' },
      { to: '/paid-leave', label: '有給' },
      ...(hasSpecialLeaveTypes ? [{ to: '/special-leave', label: '特別休暇' }] : []),
      { to: '/attendance/monthly-drafts', label: '月次勤怠下書き' },
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
      { to: '/attendance/months/to-approve', label: '月次勤怠承認' },
      { to: '/paid-leave/to-approve', label: '有給申請承認' },
      ...(hasSpecialLeaveTypes ? [{ to: '/special-leave/to-approve', label: '特別休暇申請承認' }] : []),
    ],
  },
  {
    label: '連携',
    icon: Plug,
    items: [{ to: '/integrations', label: 'API・MCP連携' }],
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
}

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

interface MobileNavProps {
  groups: NavGroup[]
  user: { name: string; department: string | null; roles?: string[] } | null
  onLogout: () => void
}

function MobileNav({ groups, user, onLogout }: MobileNavProps) {
  const [open, setOpen] = useState(false)
  const { pathname } = useLocation()

  return (
    <Sheet open={open} onOpenChange={setOpen}>
      <SheetTrigger asChild>
        <button
          type="button"
          aria-label="メニューを開く"
          className="flex size-9 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground sm:hidden"
        >
          <Menu className="size-5" aria-hidden="true" />
        </button>
      </SheetTrigger>
      <SheetContent side="left" className="flex flex-col">
        <SheetHeader>
          <SheetTitle>メニュー</SheetTitle>
        </SheetHeader>
        <nav className="flex flex-1 flex-col gap-4 overflow-y-auto" aria-label="メインナビゲーション(モバイル)">
          {groups.map((group) => (
            <div key={group.label} className="flex flex-col gap-1">
              <span className="flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                <group.icon className="size-3.5 shrink-0" aria-hidden="true" />
                {group.label}
              </span>
              <div className="flex flex-col gap-0.5">
                {group.items.map((item) => (
                  <Link
                    key={item.to}
                    to={item.to}
                    onClick={() => setOpen(false)}
                    className={cn(
                      'rounded-md px-2 py-1.5 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground',
                      isItemActive(pathname, item.to) && 'bg-accent font-medium text-foreground',
                    )}
                  >
                    {item.label}
                  </Link>
                ))}
              </div>
            </div>
          ))}
        </nav>
        {user && (
          <div className="flex items-center justify-between gap-3 border-t border-border pt-4">
            <div className="flex flex-col leading-tight">
              <span className="text-sm text-foreground">{user.name}</span>
              <span className="text-xs text-muted-foreground">
                {[user.department, user.roles?.map((role) => ROLE_LABEL[role as RoleCode] ?? role).join(' / ')]
                  .filter(Boolean)
                  .join(' ・ ')}
              </span>
            </div>
            <Button
              variant="secondary"
              onClick={() => {
                setOpen(false)
                onLogout()
              }}
            >
              ログアウト
            </Button>
          </div>
        )}
      </SheetContent>
    </Sheet>
  )
}

export function AppLayout() {
  const { user, logout } = useAuth()
  const currentYearMonth = formatDate(new Date()).slice(0, 7)
  const { data: specialLeaveTypes } = useSpecialLeaveTypes()
  const hasSpecialLeaveTypes = (specialLeaveTypes ?? []).some((type) => type.is_active)
  const visibleGroups = navGroups(currentYearMonth, hasSpecialLeaveTypes).filter(
    (group) => !group.roles || hasAnyRole(user?.roles, group.roles),
  )

  return (
    <div className="flex min-h-screen flex-col">
      <header className="border-b border-border bg-card py-3">
        <div className="mx-auto flex w-full max-w-4xl flex-col gap-2 px-4 sm:px-6 lg:max-w-6xl lg:px-8">
          <div className="flex items-center justify-between gap-4">
            <div className="flex items-center gap-2">
              <MobileNav groups={visibleGroups} user={user} onLogout={() => void logout()} />
              <span className="text-sm font-semibold text-foreground">flow-office</span>
            </div>
            <div className="flex items-center gap-2 sm:gap-3">
              <NotificationBell />
              <div className="hidden items-center gap-3 sm:flex">
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
          </div>
          <nav className="hidden flex-wrap items-center gap-1 sm:flex" aria-label="メインナビゲーション">
            {visibleGroups.map((group) => (
              <NavGroupMenu key={group.label} group={group} />
            ))}
          </nav>
        </div>
      </header>
      <main className="mx-auto w-full max-w-4xl flex-1 p-4 sm:p-6 lg:max-w-6xl lg:p-8">
        <Outlet />
      </main>
    </div>
  )
}
