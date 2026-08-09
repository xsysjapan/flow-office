import { ArrowLeft, Menu } from "lucide-react";
import { useState } from "react";
import { Link, NavLink, Outlet } from "react-router-dom";
import { useAuth } from "../../auth/useAuth";
import { Button } from "../Button/Button";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "../ui/sheet";
import { cn } from "../../lib/utils";
import { adminNavGroups, canAccessAdminItem } from "./adminNavGroups";

type NavigationProps = {
  groups: typeof adminNavGroups;
  onNavigate?: () => void;
};

function AdminNavigation({ groups, onNavigate }: NavigationProps) {
  return (
    <nav className="flex flex-col gap-5" aria-label="管理メニュー">
      <Link
        to="/admin"
        className="text-sm font-semibold text-foreground"
        onClick={onNavigate}
      >
        管理メニュー
      </Link>
      {groups.map((group) => (
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
                onClick={onNavigate}
                className={({ isActive }) =>
                  cn(
                    "rounded-md px-2 py-1 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground",
                    isActive && "bg-accent font-semibold text-foreground",
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
        onClick={onNavigate}
        className="mt-2 flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft className="size-3.5" aria-hidden="true" />
        アプリに戻る
      </Link>
    </nav>
  );
}

export function AdminLayout() {
  const { user } = useAuth();
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const visibleGroups = adminNavGroups
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => canAccessAdminItem(user, item)),
    }))
    .filter((group) => group.items.length > 0);

  return (
    <div className="flex flex-col gap-6 sm:flex-row">
      <div className="sm:hidden">
        <Sheet open={mobileMenuOpen} onOpenChange={setMobileMenuOpen}>
          <SheetTrigger asChild>
            <Button variant="secondary">
              <Menu className="size-4" aria-hidden="true" />
              管理メニューを開く
            </Button>
          </SheetTrigger>
          <SheetContent className="overflow-y-auto">
            <SheetHeader>
              <SheetTitle>管理メニュー</SheetTitle>
              <SheetDescription>
                管理する項目を選択してください。
              </SheetDescription>
            </SheetHeader>
            <AdminNavigation
              groups={visibleGroups}
              onNavigate={() => setMobileMenuOpen(false)}
            />
          </SheetContent>
        </Sheet>
      </div>
      <aside className="hidden w-56 shrink-0 sm:block">
        <AdminNavigation groups={visibleGroups} />
      </aside>
      <div className="min-w-0 flex-1">
        <Outlet />
      </div>
    </div>
  );
}
