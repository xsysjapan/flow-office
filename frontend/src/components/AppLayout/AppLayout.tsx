import { NavLink, Outlet } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import { Button } from '../Button/Button'
import './AppLayout.css'

const navItems = [
  { to: '/', label: '今日の勤怠' },
  { to: '/requests', label: '自分の申請' },
  { to: '/requests/new', label: '新規申請' },
  { to: '/approvals', label: '承認待ち' },
]

export function AppLayout() {
  const { user, logout } = useAuth()

  return (
    <div className="fo-app-layout">
      <header className="fo-app-layout__header">
        <span className="fo-app-layout__brand">flow-office</span>
        <nav className="fo-app-layout__nav">
          {navItems.map((item) => (
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
