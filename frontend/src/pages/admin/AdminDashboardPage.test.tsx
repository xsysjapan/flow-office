import { render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { describe, expect, it, vi } from "vitest";
import type { User } from "../../api/types";
import { AuthContext, type AuthContextValue } from "../../auth/AuthContext";
import { AdminDashboardPage } from "./AdminDashboardPage";
import { adminNavGroups } from "../../components/AdminLayout/adminNavGroups";

const mockUser: User = {
  id: "user-1",
  name: "山田 太郎",
  email: "yamada@example.com",
  department: "開発部",
  job_title: null,
  employment_status: "active",
  last_login_at: null,
  effective_features: ["administration.users", "administration.settings", "attendance.entry", "attendance.timesheet", "workflow.requests", "paid_leave.requests", "backoffice.expenses"],
  effective_permissions: adminNavGroups.flatMap((group) => group.items.flatMap((item) => [item.permission, ...(item.permissions ?? [])].filter((permission): permission is string => Boolean(permission)))),
};

function renderPage(user: User = mockUser) {
  const authValue: AuthContextValue = {
    user,
    status: "authenticated",
    login: vi.fn(),
    completeLogin: vi.fn(),
    applySession: vi.fn(),
    logout: vi.fn(),
  };

  return render(
    <AuthContext.Provider value={authValue}>
      <MemoryRouter>
        <AdminDashboardPage />
      </MemoryRouter>
    </AuthContext.Provider>,
  );
}

describe("AdminDashboardPage", () => {
  it("shows a card for each admin function visible to an admin user", () => {
    renderPage();

    expect(screen.getByRole("link", { name: /^ユーザー/ })).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: /^グループ 組織/ }),
    ).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /^所属変更/ })).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: /^ロール割当/ }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: /^ロール定義/ }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: /^グループ種別/ }),
    ).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /申請種別/ })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /監査ログ/ })).toBeInTheDocument();
  });

  it("shows only human-resources cards from effective access", () => {
    renderPage({ ...mockUser, effective_features: ["administration.users"], effective_permissions: ["user.view", "group.view"] });

    expect(screen.getByRole("link", { name: /^ユーザー/ })).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: /^グループ 組織/ }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: /^ロール割当/ }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: /^ロール定義/ }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: /^グループ種別/ }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: /申請種別/ }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: /監査ログ/ }),
    ).not.toBeInTheDocument();
  });

  it("shows a permission denied state when the user has no accessible admin function", () => {
    renderPage({ ...mockUser, effective_features: [], effective_permissions: [] });

    expect(
      screen.getByText("管理メニューにアクセスできる権限がありません。必要な場合は管理者に付与を依頼してください。"),
    ).toBeInTheDocument();
    expect(screen.queryByRole("link")).not.toBeInTheDocument();
  });
});
