import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { Route, Routes } from "react-router-dom";
import { describe, expect, it, vi } from "vitest";
import * as accessControlApi from "../../api/accessControl";
import * as userManagementApi from "../../api/userManagement";
import * as usersApi from "../../api/users";
import type { GroupType, ManagedGroup } from "../../api/userManagement";
import { renderWithGuard } from "../../test/renderWithGuard";
import { GroupDetailPage } from "./GroupDetailPage";

vi.mock("../../lib/notify", () => ({ notify: { success: vi.fn() } }));

const groupType: GroupType = {
  id: 1,
  code: "ORGANIZATION",
  name: "組織",
  display_order: 1,
  status: "active",
  is_system: true,
  membership_limit_type: "unlimited",
  max_memberships_per_user: null,
  requires_primary_membership: false,
  max_primary_memberships: 1,
};

const base: ManagedGroup = {
  id: "group-parent",
  group_type_id: 1,
  name: "本社",
  code: "HEAD_OFFICE",
  status: "active",
  parent_group_id: null,
  memberships_count: 0,
  type: groupType,
  features: [],
  memberships: [],
  role_assignments: [],
};

describe("GroupDetailPage の離脱警告", () => {
  it("作成して詳細へ移るとき、確認のDialogは出ない", async () => {
    const created: ManagedGroup = { ...base, id: "group-new", name: "新規グループ" };
    const fetchGroups = vi
      .spyOn(userManagementApi, "fetchManagedGroups")
      .mockResolvedValue([base]);
    vi.spyOn(userManagementApi, "fetchGroupTypes").mockResolvedValue([groupType]);
    vi.spyOn(userManagementApi, "fetchMembershipChangeSets").mockResolvedValue([]);
    vi.spyOn(userManagementApi, "createGroup").mockResolvedValue({ id: "group-new" });
    vi.spyOn(usersApi, "fetchUsers").mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, total: 0 },
      links: { next: null, prev: null },
    });
    vi.spyOn(accessControlApi, "fetchAccessRoles").mockResolvedValue([]);
    vi.spyOn(accessControlApi, "fetchRoleAssignments").mockResolvedValue([]);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    renderWithGuard(
      <QueryClientProvider client={queryClient}>
        <Routes>
          <Route path="/admin/groups/:id" element={<GroupDetailPage />} />
        </Routes>
      </QueryClientProvider>,
      { initialEntries: ["/admin/groups/new"] },
    );

    await screen.findByRole("heading", { name: "基本情報" });
    await userEvent.selectOptions(screen.getByLabelText("グループ種別"), "1");
    await userEvent.type(screen.getByLabelText("名称"), "新規グループ");
    fetchGroups.mockResolvedValue([base, created]);
    await userEvent.click(screen.getByRole("button", { name: "作成" }));

    expect(await screen.findByRole("heading", { name: "新規グループ" })).toBeInTheDocument();
    expect(screen.queryByText("保存していない変更があります")).not.toBeInTheDocument();
  });
});
