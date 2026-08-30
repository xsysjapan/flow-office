import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { describe, expect, it, vi } from "vitest";
import type { User } from "../../api/types";
import { AuthContext, type AuthContextValue } from "../../auth/AuthContext";
import { AdminLayout } from "./AdminLayout";
import { adminNavGroups } from "./adminNavGroups";

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

function renderLayout(user: User = mockUser) {
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
      <MemoryRouter initialEntries={["/admin"]}>
        <Routes>
          <Route path="/admin" element={<AdminLayout />}>
            <Route index element={<p>管理メニューの中身</p>} />
          </Route>
        </Routes>
      </MemoryRouter>
    </AuthContext.Provider>,
  );
}

describe("AdminLayout", () => {
  it("separates HR operations from system access management", () => {
    const humanResources = adminNavGroups.find(
      (group) => group.label === "人事・組織",
    );
    const system = adminNavGroups.find((group) => group.label === "システム");

    expect(humanResources?.items.map((item) => item.label)).toEqual([
      "ユーザー",
      "グループ",
      "所属変更",
      "人事データ連携",
    ]);
    expect(system?.items.map((item) => item.label)).toEqual(
      expect.arrayContaining([
        "ロール割当",
        "ロール定義",
        "ID・管理元設定",
        "グループ種別",
      ]),
    );
  });

  it("shows the routed content and sidebar links for an admin user", () => {
    renderLayout();

    expect(screen.getByText("管理メニューの中身")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "ユーザー" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "グループ" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "所属変更" })).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "ロール割当" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "ロール定義" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "グループ種別" }),
    ).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "申請種別" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "監査ログ" })).toBeInTheDocument();
  });

  it("shows only human-resources sections from effective access", () => {
    renderLayout({ ...mockUser, effective_features: ["administration.users"], effective_permissions: ["user.view", "group.view", "group.change.schedule", "external_hr.import"] });

    expect(screen.getByRole("link", { name: "ユーザー" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "グループ" })).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "人事データ連携" }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: "ロール割当" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: "ロール定義" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: "ID・管理元設定" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: "グループ種別" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: "申請種別" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: "監査ログ" }),
    ).not.toBeInTheDocument();
  });

  it("has a link back to the main app", () => {
    renderLayout();

    expect(
      screen.getByRole("link", { name: /アプリに戻る/ }),
    ).toBeInTheDocument();
  });

  it("opens the management navigation from the mobile menu button", async () => {
    const user = userEvent.setup();
    renderLayout();

    await user.click(
      screen.getByRole("button", { name: "管理メニューを開く" }),
    );

    const dialog = screen.getByRole("dialog", { name: "管理メニュー" });
    expect(dialog).toHaveTextContent("人事・組織");
    expect(dialog).toHaveTextContent("グループ種別");
    await user.keyboard("{Escape}");
    expect(
      screen.queryByRole("dialog", { name: "管理メニュー" }),
    ).not.toBeInTheDocument();
  });
});
