import type { ReactNode } from "react";
import { Navigate, useLocation } from "react-router-dom";
import {
  adminNavGroups,
  canAccessAdminItem,
  type AdminNavItem,
} from "../components/AdminLayout/adminNavGroups";
import { useAuth } from "./useAuth";

function matchedItem(pathname: string): AdminNavItem | undefined {
  return adminNavGroups
    .flatMap((group) => group.items)
    .filter(
      (item) => pathname === item.to || pathname.startsWith(`${item.to}/`),
    )
    .sort((a, b) => b.to.length - a.to.length)[0];
}

/** Featureで画面の利用可否を、Permissionで操作可否を判定する。旧ユーザーロールは参照しない。 */
export function RequireAdminRoute({ children }: { children: ReactNode }) {
  const { user } = useAuth();
  const location = useLocation();
  const item = matchedItem(location.pathname);
  const allowed = item
    ? canAccessAdminItem(user, item)
    : location.pathname === "/admin" &&
      adminNavGroups.some((group) =>
        group.items.some((candidate) => canAccessAdminItem(user, candidate)),
      );

  if (!allowed) return <Navigate to="/" replace />;
  return <>{children}</>;
}
