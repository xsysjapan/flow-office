import { NavLink, Outlet } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import { hasAnyRole, ROLE, type RoleCode } from '../../utils/roles'
import { Button } from '../Button/Button'
import './AppLayout.css'

interface NavItem {
  to: string
  label: string
}

interface NavGroup {
  label: string
  items: NavItem[]
  /** 未指定なら全ユーザーに表示。指定時はいずれかのロールを持つユーザーにのみグループごと表示する。 */
  roles?: RoleCode[]
}

const navGroups: NavGroup[] = [
  {
    label: '勤怠',
    items: [
      { to: '/', label: '今日の勤怠' },
      { to: '/attendance/week', label: '週次勤怠' },
      { to: '/attendance/months', label: '勤怠月次' },
      { to: '/paid-leave', label: '有給' },
    ],
  },
  {
    label: '申請',
    items: [
      { to: '/requests', label: '自分の申請' },
      { to: '/requests/new', label: '新規申請' },
    ],
  },
  {
    label: '承認',
    items: [
      { to: '/approvals', label: '承認待ち' },
      { to: '/attendance/months/to-approve', label: '勤怠月次承認' },
      { to: '/paid-leave/to-approve', label: '有給申請承認' },
    ],
  },
  {
    label: 'バックオフィス',
    roles: [ROLE.BACKOFFICE_STAFF, ROLE.ACCOUNTING_STAFF, ROLE.GENERAL_AFFAIRS_STAFF, ROLE.ADMIN],
    items: [{ to: '/backoffice-tasks', label: 'タスク一覧' }],
  },
  {
    label: '管理',
    roles: [ROLE.ADMIN, ROLE.HR_STAFF],
    items: [{ to: '/admin', label: '管理メニュー' }],
  },
]

export function AppLayout() {
  const { user, logout } = useAuth()
  const visibleGroups = navGroups.filter((group) => !group.roles || hasAnyRole(user?.roles, group.roles))

  return (
    <div className="fo-app-layout">
      <header className="fo-app-layout__header">
        <span className="fo-app-layout__brand">flow-office</span>
        <nav className="fo-app-layout__nav">
          {visibleGroups.map((group) => (
            <div className="fo-app-layout__nav-group" key={group.label}>
              <span className="fo-app-layout__nav-group-label">{group.label}</span>
              <div className="fo-app-layout__nav-group-links">
                {group.items.map((item) => (
                  <NavLink
                    key={item.to}
                    to={item.to}
                    end={item.to === '/'}
                    className={({ isActive }) => (isActive ? 'is-active' : undefined)}
                  >
                    {item.label}
                  </NavLink>
                ))}
              </div>
            </div>
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
