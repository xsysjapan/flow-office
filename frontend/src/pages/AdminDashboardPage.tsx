import { Link } from 'react-router-dom'
import { adminNavGroups } from '../components/AdminLayout/adminNavGroups'
import { useAuth } from '../auth/useAuth'
import { hasAnyRole } from '../utils/roles'
import './AdminDashboardPage.css'

/** 管理メニューのトップ画面。管理系の各機能をカード形式でまとめて一覧表示する。 */
export function AdminDashboardPage() {
  const { user } = useAuth()
  const visibleGroups = adminNavGroups.filter((group) => !group.roles || hasAnyRole(user?.roles, group.roles))

  return (
    <div className="admin-dashboard">
      <h1>管理メニュー</h1>
      {visibleGroups.map((group) => (
        <section className="admin-dashboard__section" key={group.label}>
          <h2>{group.label}</h2>
          <div className="admin-dashboard__grid">
            {group.items.map((item) => (
              <Link key={item.to} to={item.to} className="admin-dashboard__card">
                <h3>{item.label}</h3>
                <p>{item.description}</p>
              </Link>
            ))}
          </div>
        </section>
      ))}
    </div>
  )
}
