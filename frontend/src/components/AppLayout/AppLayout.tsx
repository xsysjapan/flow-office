import { NavLink, Outlet } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import { hasAnyRole, ROLE, type RoleCode } from '../../utils/roles'
import { Button } from '../Button/Button'
import './AppLayout.css'

interface NavItem {
  to: string
  label: string
  /** 未指定なら全ユーザーに表示。指定時はいずれかのロールを持つユーザーにのみ表示する。 */
  roles?: RoleCode[]
}

const navItems: NavItem[] = [
  { to: '/', label: '今日の勤怠' },
  { to: '/attendance/week', label: '週次勤怠' },
  { to: '/requests', label: '自分の申請' },
  { to: '/requests/new', label: '新規申請' },
  { to: '/approvals', label: '承認待ち' },
  { to: '/attendance/months', label: '勤怠月次' },
  { to: '/attendance/months/to-approve', label: '勤怠月次承認' },
  { to: '/paid-leave', label: '有給' },
  {
    to: '/backoffice-tasks',
    label: 'バックオフィス',
    roles: [ROLE.BACKOFFICE_STAFF, ROLE.ACCOUNTING_STAFF, ROLE.GENERAL_AFFAIRS_STAFF, ROLE.ADMIN],
  },
  { to: '/admin/users', label: 'ユーザー・権限', roles: [ROLE.ADMIN, ROLE.HR_STAFF] },
  { to: '/admin/request-types', label: '申請種別', roles: [ROLE.ADMIN] },
  { to: '/admin/work-calendars', label: 'カレンダー', roles: [ROLE.ADMIN, ROLE.HR_STAFF] },
  { to: '/admin/work-styles', label: '勤務形態・シフト', roles: [ROLE.ADMIN, ROLE.HR_STAFF] },
  { to: '/admin/paid-leave', label: '有給ルール', roles: [ROLE.ADMIN, ROLE.HR_STAFF] },
  { to: '/admin/audit-log', label: '監査ログ', roles: [ROLE.ADMIN] },
]

export function AppLayout() {
  const { user, logout } = useAuth()
  const visibleNavItems = navItems.filter((item) => !item.roles || hasAnyRole(user?.roles, item.roles))

  return (
    <div className="fo-app-layout">
      <header className="fo-app-layout__header">
        <span className="fo-app-layout__brand">flow-office</span>
        <nav className="fo-app-layout__nav">
          {visibleNavItems.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.to === '/'}
              className={({ isActive }) => (isActive ? 'is-active' : undefined)}
            >
              {item.label}
            </NavLink>
          ))}
        </nav>
        <div className="fo-app-layout__user">
          {user && <span>{user.name}</span>}
          <Button variant="secondary" onClick={() => void logout()}>
            ログアウト
          </Button>
        </div>
      </header>
      <main className="fo-app-layout__content">
        <Outlet />
      </main>
    </div>
  )
}
