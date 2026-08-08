import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { adminNavGroups, canAccessAdminItem, type AdminNavItem } from '../components/AdminLayout/adminNavGroups'
import { hasAnyRole, type RoleCode } from '../utils/roles'
import { useAuth } from './useAuth'

const ALL_ADMIN_ROLES = Array.from(new Set(adminNavGroups.flatMap((group) => group.roles ?? []))) as RoleCode[]

/**
 * /admin配下の各パスに必要なロールをadminNavGroupsから逆引きする。
 * サブパス(例: /admin/users/:id)は最も長く前方一致した項目のロールを採用する。
 * どの項目にも一致しない場合(例: /adminのダッシュボード直下)は、
 * 管理メニューのいずれかにアクセスできるロールを持っていればよい。
 */
function requiredRolesFor(pathname: string): RoleCode[] {
  const matches = adminNavGroups.flatMap((group) =>
    group.items
      .filter((item) => pathname === item.to || pathname.startsWith(`${item.to}/`))
      .map((item) => ({ to: item.to, roles: group.roles })),
  )

  if (matches.length === 0) {
    return ALL_ADMIN_ROLES
  }

  const longestMatch = matches.reduce((a, b) => (b.to.length > a.to.length ? b : a))
  return longestMatch.roles ?? ALL_ADMIN_ROLES
}

function matchedItem(pathname: string): { item: AdminNavItem; roles?: RoleCode[] } | undefined {
  return adminNavGroups.flatMap(group=>group.items.filter(item=>pathname===item.to||pathname.startsWith(`${item.to}/`)).map(item=>({item,roles:group.roles}))).sort((a,b)=>b.item.to.length-a.item.to.length)[0]
}

export function RequireAdminRoute({ children }: { children: ReactNode }) {
  const { user } = useAuth()
  const location = useLocation()

  const match=matchedItem(location.pathname)
  const allowed=match?canAccessAdminItem(user,match.item,match.roles):hasAnyRole(user?.roles,requiredRolesFor(location.pathname))
  if (!allowed) {
    return <Navigate to="/" replace />
  }

  return <>{children}</>
}
