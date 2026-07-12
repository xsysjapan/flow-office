import { ArrowLeft } from 'lucide-react'
import { Link, NavLink, Outlet } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import { cn } from '../../lib/utils'
import { hasAnyRole } from '../../utils/roles'
import { adminNavGroups } from './adminNavGroups'

export function AdminLayout() {
  const { user } = useAuth()
  const visibleGroups = adminNavGroups.filter((group) => !group.roles || hasAnyRole(user?.roles, group.roles))

  return (
    <div className="flex flex-col gap-6 p-4 sm:flex-row sm:p-6">
      <aside className="flex w-full shrink-0 flex-col gap-5 sm:w-56" aria-label="管理メニュー">
        <Link to="/admin" className="text-sm font-semibold text-foreground">
          管理メニュー
        </Link>
        {visibleGroups.map((group) => (
          <div key={group.label} className="flex flex-col gap-1.5">
            <span className="flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase">
              <group.icon className="size-3.5" aria-hidden="true" />
              {group.label}
            </span>
            <div className="flex flex-col gap-0.5">
              {group.items.map((item) => (
                <NavLink
                  key={item.to}
                  to={item.to}
                  end
                  className={({ isActive }) =>
                    cn(
                      'rounded-md px-2 py-1 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground',
                      isActive && 'bg-accent font-semibold text-foreground',
                    )
                  }
                >
                  {item.label}
                </NavLink>
              ))}
            </div>
          </div>
        ))}
        <Link
          to="/"
          className="mt-2 flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft className="size-3.5" aria-hidden="true" />
          アプリに戻る
        </Link>
      </aside>
      <div className="min-w-0 flex-1">
        <Outlet />
      </div>
    </div>
  )
}
