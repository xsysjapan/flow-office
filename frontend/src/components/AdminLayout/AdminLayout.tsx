import { Link, NavLink, Outlet } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import { hasAnyRole } from '../../utils/roles'
import { adminNavGroups } from './adminNavGroups'
import './AdminLayout.css'

export function AdminLayout() {
  const { user } = useAuth()
  const visibleGroups = adminNavGroups.filter((group) => !group.roles || hasAnyRole(user?.roles, group.roles))

  return (
    <div className="fo-admin-layout">
      <aside className="fo-admin-layout__sidebar">
        <Link to="/admin" className="fo-admin-layout__title">
          管理メニュー
        </Link>
        {visibleGroups.map((group) => (
          <div className="fo-admin-layout__group" key={group.label}>
            <span className="fo-admin-layout__group-label">{group.label}</span>
            {group.items.map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                end
                className={({ isActive }) => (isActive ? 'is-active' : undefined)}
              >
                {item.label}
              </NavLink>
            ))}
          </div>
        ))}
        <Link to="/" className="fo-admin-layout__back">
          ← アプリに戻る
        </Link>
      </aside>
      <div className="fo-admin-layout__content">
        <Outlet />
      </div>
    </div>
  )
}
