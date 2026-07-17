import { Link } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import { adminNavGroups } from '../../components/AdminLayout/adminNavGroups'
import { hasAnyRole } from '../../utils/roles'

/** 管理メニューのトップ画面。管理系の各機能をカード形式でまとめて一覧表示する。 */
export function AdminDashboardPage() {
  const { user } = useAuth()
  const visibleGroups = adminNavGroups.filter((group) => !group.roles || hasAnyRole(user?.roles, group.roles))

  return (
    <div className="flex flex-col gap-8">
      <h1 className="text-lg font-semibold text-foreground">管理メニュー</h1>
      {visibleGroups.map((group) => (
        <section key={group.label} className="flex flex-col gap-3">
          <h2 className="flex items-center gap-2 text-sm font-semibold text-foreground">
            <group.icon className="size-4 text-muted-foreground" aria-hidden="true" />
            {group.label}
          </h2>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {group.items.map((item) => (
              <Link
                key={item.to}
                to={item.to}
                className="flex flex-col gap-1 rounded-lg border border-border p-4 transition-colors outline-none hover:border-primary/50 hover:bg-accent/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
              >
                <h3 className="text-sm font-medium text-foreground">{item.label}</h3>
                <p className="text-xs text-muted-foreground">{item.description}</p>
              </Link>
            ))}
          </div>
        </section>
      ))}
    </div>
  )
}
