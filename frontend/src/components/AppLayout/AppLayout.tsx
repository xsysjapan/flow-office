import { useState } from "react";
import { Link, NavLink, Navigate, Outlet, useLocation } from "react-router-dom";
import { Menu } from "lucide-react";
import { useAuth } from "../../auth/useAuth";
import { useSpecialLeaveTypes } from "../../hooks/useSpecialLeave";
import { cn } from "../../lib/utils";
import { formatDate } from "../../utils/weekDates";
import {
  adminNavGroups,
  canAccessAdminItem,
} from "../AdminLayout/adminNavGroups";
import {
  buildNavGroups,
  HOME_PATH,
  isPathActive,
  navGroupMeta,
  requiredFeaturesForPath,
  type NavContext,
  type ResolvedNavGroup,
} from "../../routes/routeManifest";
import { Button } from "../Button/Button";
import { NotificationBell } from "../NotificationBell/NotificationBell";
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "../ui/sheet";

interface SidebarProps {
  groups: ResolvedNavGroup[];
  onNavigate?: () => void;
}

/** 左サイドバー(PC)・詳細メニューSheet(モバイル)共通のナビ本体。
 *  グループ見出し+項目一覧を常時展開して表示する(AdminLayoutの構造を踏襲)。 */
function NavSections({ groups, onNavigate }: SidebarProps) {
  const { pathname } = useLocation();
  return (
    <nav className="flex flex-col gap-5" aria-label="メインナビゲーション">
      <Link
        to={HOME_PATH}
        onClick={onNavigate}
        className={cn(
          "flex items-center gap-1.5 rounded-md px-2 py-1.5 text-sm font-medium transition-colors hover:bg-accent",
          pathname === HOME_PATH
            ? "bg-accent text-foreground"
            : "text-muted-foreground hover:text-foreground",
        )}
      >
        <navGroupMeta.home.icon className="size-4 shrink-0" aria-hidden="true" />
        ホーム
      </Link>
      {groups.map((group) => (
        <div key={group.key} className="flex flex-col gap-1.5">
          <span className="flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase">
            <group.icon className="size-3.5 shrink-0" aria-hidden="true" />
            {group.label}
          </span>
          <div className="flex flex-col gap-0.5">
            {group.items.map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                onClick={onNavigate}
                className={cn(
                  "rounded-md px-2 py-1.5 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground",
                  isPathActive(pathname, item.to) &&
                    "bg-accent font-medium text-foreground",
                )}
              >
                {item.label}
              </NavLink>
            ))}
          </div>
        </div>
      ))}
    </nav>
  );
}

interface MobileMenuProps extends SidebarProps {
  user: { name: string; department: string | null; roles?: string[] } | null;
  onLogout: () => void;
}

/** モバイルのハンバーガーから開く詳細メニュー(全項目)。モバイルではこのメニューが
 *  唯一のナビ導線になる(ボトムナビは廃止済み)。 */
function MobileMenu({ groups, user, onLogout }: MobileMenuProps) {
  const [open, setOpen] = useState(false);

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
      <SheetContent side="left" className="flex flex-col overflow-y-auto">
        <SheetHeader>
          <SheetTitle>メニュー</SheetTitle>
        </SheetHeader>
        <div className="flex-1 overflow-y-auto">
          <NavSections groups={groups} onNavigate={() => setOpen(false)} />
        </div>
        {user && (
          <div className="flex items-center justify-between gap-3 border-t border-border pt-4">
            <div className="flex flex-col leading-tight">
              <span className="text-sm text-foreground">{user.name}</span>
              <span className="text-xs text-muted-foreground">
                {user.department}
              </span>
            </div>
            <Button
              variant="secondary"
              onClick={() => {
                setOpen(false);
                onLogout();
              }}
            >
              ログアウト
            </Button>
          </div>
        )}
      </SheetContent>
    </Sheet>
  );
}

export function AppLayout() {
  const { user, logout } = useAuth();
  const { pathname } = useLocation();
  const currentYearMonth = formatDate(new Date()).slice(0, 7);
  const { data: specialLeaveTypes } = useSpecialLeaveTypes(
    user?.effective_features === undefined ||
      user.effective_features.includes("paid_leave.requests"),
  );
  const hasSpecialLeaveTypes = (specialLeaveTypes ?? []).some(
    (type) => type.is_active,
  );
  const canSeeBackOfficeTasks = Boolean(
    user?.effective_features?.includes("backoffice.tasks"),
  );
  const canAccessAdmin = adminNavGroups.some((adminGroup) =>
    adminGroup.items.some((adminItem) => canAccessAdminItem(user, adminItem)),
  );

  const navContext: NavContext = {
    currentYearMonth,
    hasSpecialLeaveTypes,
    canSeeBackOfficeTasks,
    canAccessAdmin,
  };
  const visibleGroups = buildNavGroups(user?.effective_features, navContext);

  if (user?.effective_features !== undefined) {
    const required = requiredFeaturesForPath(pathname);
    if (
      required.length > 0 &&
      !required.some((feature) => user.effective_features?.includes(feature))
    ) {
      return <Navigate to="/account" replace />;
    }
  }

  return (
    <div className="flex min-h-screen flex-col sm:flex-row">
      <aside className="hidden w-56 shrink-0 border-r border-border bg-card p-4 sm:block">
        <Link
          to={HOME_PATH}
          aria-label="ホームに戻る"
          className="mb-5 block text-sm font-semibold text-foreground hover:text-foreground/80"
        >
          flow-office
        </Link>
        <NavSections groups={visibleGroups} />
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="border-b border-border bg-card py-3">
          <div className="flex items-center justify-between gap-4 px-4 sm:px-6">
            <div className="flex items-center gap-2">
              <MobileMenu
                groups={visibleGroups}
                user={user}
                onLogout={() => void logout()}
              />
              <Link
                to={HOME_PATH}
                aria-label="ホームに戻る"
                className="text-sm font-semibold text-foreground sm:hidden"
              >
                flow-office
              </Link>
            </div>
            <div className="flex items-center gap-2 sm:gap-3">
              <NotificationBell />
              <div className="hidden items-center gap-3 sm:flex">
                {user && (
                  <div className="flex flex-col items-end leading-tight">
                    <span className="text-sm text-muted-foreground">
                      {user.name}
                    </span>
                    <span className="text-xs text-muted-foreground">
                      {user.department}
                    </span>
                  </div>
                )}
                <Button variant="secondary" onClick={() => void logout()}>
                  ログアウト
                </Button>
              </div>
            </div>
          </div>
        </header>
        <main className="w-full flex-1 p-4 sm:p-6 lg:p-8">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
